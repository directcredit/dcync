#!/usr/bin/php
<?php

/**
 * dcync
 *
 * @author <masterklavi@gmail.com>
 * @version 0.2b
 */

class dcync
{
    const VERSION = '0.2b';

    protected $homeDir = null;

    public function __construct()
    {
        $this->homeDir = getenv('HOME');
    }

    public function main()
    {
        global $argc, $argv;

        if (PHP_SAPI !== 'cli') {
            echo 'cli sapi only supported!', PHP_EOL;
            exit(1);
        }

        if (posix_getuid() === 0) {
            echo 'root mode is disabled', PHP_EOL;
            exit(1);
        }

        try {
            // load action by first argument

            if ($argc > 1) {

                $method = 'do' . $argv[1];

                if (method_exists($this, $method)) {
                    return call_user_func([$this, $method]);
                }
            }

            // load default action

            return $this->doDefault();

        } catch (Exception $e) {
            echo 'Error: ', $e->getMessage(), PHP_EOL;
        }
    }


    ///////////////////////// ACTIONS  /////////////////////////


    /**
     * Calls help action or version action
     */
    protected function doDefault()
    {
        $options = getopt('hV');

        if (isset($options['h'])) {
            return $this->doHelp();

        } elseif (isset($options['V'])) {
            return $this->doVersion();

        } else {
            return $this->doHelp();
        }
    }

    /**
     * Shows help
     */
    protected function doHelp()
    {
        echo
            "dcync v" . self::VERSION, PHP_EOL,
            PHP_EOL,
            "List of options:", PHP_EOL,
            " -V               prints current version of dcync", PHP_EOL,
            " -h               prints this help", PHP_EOL,
            PHP_EOL,
            "List of commands:", PHP_EOL,
            PHP_EOL,
            " init             configures your folder for dcync", PHP_EOL,
            " run              runs sync process for changed files", PHP_EOL,
            " destroy          removes your folder from dcync", PHP_EOL,
            PHP_EOL,
            " template         adds template for push/pull", PHP_EOL,
            " push             pushes data to remote folder", PHP_EOL,
            " pull             pulls data from remote folder", PHP_EOL,
            PHP_EOL
        ;
    }

    /**
     * Shows version
     */
    protected function doVersion()
    {
        echo "dcync v" . self::VERSION, PHP_EOL;
    }

    /**
     * Inits project
     *
     * - creates local .dcync file
     * - adds project to global .dcync file
     * - tries to create folder on remote
     */
    protected function doInit()
    {
        $options = $this->parseArgs('vh');

        if (isset($options['h'])) {
            echo
                "Syntax: dcync init", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " -v            extended output", PHP_EOL,
                " -h            prints this help", PHP_EOL,
                PHP_EOL
            ;
            return;
        }

        $verbose = isset($options['v']);

        // local config

        echo "Hi, let's configure your folder for dcync ;)", PHP_EOL;

        $config = new stdClass;
        $remote = rtrim(trim(readline("Remote directory (ex.: user@remote:" . getcwd() . "): ")), '/');

        if (!preg_match('/^(?<user>.+?)@(?<host>.+?):(?<path>.+?)$/', $remote, $match)) {
            throw new Exception('invalid remote directory');
        }

        $config->remote = (object)array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);

        echo "Folders/files to exclude:", PHP_EOL;

        $config->exclude = [];

        while (true) {
            $line = trim(readline('- '));
            if ($line) {
                $config->exclude[] = $line;
            } else {
                break;
            }
        }

        $config->templates = new stdClass;

        if ($verbose) {
            echo "* saving local config:", PHP_EOL;
            print_r($config);
        }

        $this->setLocalConfig($config);

        // global config

        $globalConfig = $this->getGlobalConfig();
        $globalConfig->projects[] = getcwd();
        $globalConfig->projects = array_unique($globalConfig->projects);

        if ($verbose) {
            echo "* saving global config", PHP_EOL;
        }

        $this->setGlobalConfig($globalConfig);

        // create directories

        if ($verbose) {
            echo "* connecting to ssh", PHP_EOL;
        }

        $session = $this->getSSHConnect($config->remote->user, $config->remote->host);

        if ($verbose) {
            echo "* sending command: mkdir -p", PHP_EOL;
        }

        ssh2_exec($session, 'mkdir -p ' . escapeshellarg($config->remote->path));

        // end message

        echo
            "Ok, saved!", PHP_EOL,
            "Hey, you can change this config later in .dcync!", PHP_EOL
        ;
    }

    /**
     * Destroys project
     *
     * - removes project from global config
     * - removes local config file
     */
    protected function doDestroy()
    {
        $options = $this->parseArgs('vh');

        if (isset($options['h'])) {
            echo
                "Syntax: dcync destroy", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " -v            extended output", PHP_EOL,
                " -h            prints this help", PHP_EOL,
                PHP_EOL
            ;
            return;
        }

        $verbose = isset($options['v']);

        // search & remove from global config

        $globalConfig = $this->getGlobalConfig();
        $k = array_search(getcwd(), $globalConfig->projects, true);

        if ($k !== false) {
            unset($globalConfig->projects[$k]);
            $globalConfig->projects = array_values($globalConfig->projects);

            if ($verbose) {
                echo "* saving global config", PHP_EOL;
            }

            $this->setGlobalConfig($globalConfig);
        } else {
            throw new Exception('project was not in global config');
        }

        // removing local config

        if ($verbose) {
            echo "* removing local config", PHP_EOL;
        }

        $this->removeLocalConfig();

        // end message

        echo "Ok, bye bye!", PHP_EOL;
    }

    /**
     * Runs sync process
     *
     * - collects actual projects
     * - searches diffs
     * - connects to unique servers
     * - uploads changes
     */
    protected function doRun()
    {
        $options = $this->parseArgs('hv', ['interval:']);

        if (isset($options['h'])) {
            echo
                "Syntax: dcync run [--interval=100]", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " --interval    iterations interval in ms", PHP_EOL,
                " -v            extended output", PHP_EOL,
                " -h            prints this help", PHP_EOL,
                PHP_EOL
            ;
            return;
        }

        $verbose    = isset($options['v']);
        $interval   = (isset($options['interval']) ? $options['interval'] : 100) * 1000 + 1000;
        $gcTick     = 1200 * 1000000 / $interval; // every 20 minutes to gc

        // begin cycle

        echo "Ok, dcync is ready for global changes ...", PHP_EOL;

        $projects = [];       // list of actual projects
        $timeFrom   = time(); // time to diff changes
        $changesMap = [];     // map to prevent often changes
        $connects = [];       // storage for active connects
        $needPing = false;    // flag to prevent often pings

        while (true) {

            // fetch global config
            $globalConfig = $this->getGlobalConfig();

            // collect projects from global config
            foreach ($globalConfig->projects as $path) {
                if (isset($projects[$path])) {
                    $project = $projects[$path];
                } else {
                    if ($verbose) {
                        echo "* add project " . $path . " to pool", PHP_EOL;
                    }

                    $project = new stdClass;
                    $project->fileList = null;
                    $project->path = $path;
                }
                $project->config = $this->getLocalConfig($path);
                $projects[$path] = $project;
            }

            // remove non-active projects
            foreach (array_keys($projects) as $path) {
                if (array_search($path, $globalConfig->projects, true) !== false) {
                    continue;
                }

                if ($verbose) {
                    echo "* remove project " . $path . " from pool", PHP_EOL;
                }

                unset($projects[$path]);
            }

            // mark new time
            $time = time();

            // build upload tasks
            $tasks = [];
            foreach ($projects as $project) {
                $modifiedList = [];
                $fileList = [];
                $this->walkTree($project->path, $timeFrom, array_flip($project->config->exclude) + ['.dcync' => 0], $fileList, $modifiedList);
                $addedList = $project->fileList !== null ? array_diff_key($fileList, $project->fileList) : [];
                $removedList = $project->fileList !== null ? array_diff_key($project->fileList, $fileList) : [];
                krsort($removedList);
                $project->fileList = $fileList;

                $userhost = $project->config->remote->user . '@' . $project->config->remote->host;

                if ($addedList || $removedList || $modifiedList) {
                    $tasks[] = [
                        'ssh'   => $connects[$userhost][0],
                        'ftp'   => $connects[$userhost][1],
                        'path'  => function ($filename) use ($project) { return $project->config->remote->path . substr($filename, strlen($project->path)); },

                        'modified'  => $modifiedList,
                        'added'     => $addedList,
                        'removed'   => $removedList,
                    ];
                }
            }

            // make & check connects
            foreach ($projects as $project) {
                $userhost = $project->config->remote->user . '@' . $project->config->remote->host;

                if (!isset($connects[$userhost])) {
                    if ($verbose) {
                        echo "* connecting to " . $userhost . "... ";
                    }

                    $ssh = $this->getSSHConnect($project->config->remote->user, $project->config->remote->host);
                    $ftp = ssh2_sftp($ssh);
                    $connects[$userhost] = [$ssh, $ftp];

                    if ($verbose) {
                        echo "ok", PHP_EOL;
                    }

                } elseif ($needPing) {
                    if ($verbose) {
                        echo "* ping via echo 1", PHP_EOL;
                    }

                    if (ssh2_exec($connects[$userhost][0], 'echo 1') === false) {
                        if ($verbose) {
                            echo "* reconnecting to " . $userhost . "... ";
                        }

                        $ssh = $this->getSSHConnect($project->config->remote->user, $project->config->remote->host);
                        $ftp = ssh2_sftp($ssh);
                        $connects[$userhost] = [$ssh, $ftp];

                        if ($verbose) {
                            echo "ok", PHP_EOL;
                        }
                    }

                    $needPing = false;
                }
            }

            // process tasks
            foreach ($tasks as $task) {

                foreach ($task['added'] as $filename => $type) {
                    if (isset($changesMap[$filename]) && $changesMap[$filename] >= $timeFrom) {
                        continue;
                    }

                    if ($verbose) {
                        echo date('y-m-d H:i:s') . '  + ' . $filename . '    ';
                    }

                    $changesMap[$filename] = $time;
                    $remoteFilename = $task['path']($filename);

                    if ($type === 1) {
                        $result = ssh2_scp_send($task['ssh'], $filename, $remoteFilename);
                    } else {
                        $result = ssh2_sftp_mkdir($task['ftp'], $remoteFilename);
                    }

                    if (!$result) {
                        $needPing = true;
                    }

                    if ($verbose) {
                        echo $result ? 'ok' : 'FAIL', PHP_EOL;
                    }
                }

                foreach ($task['removed'] as $filename => $type) {
                    if ($verbose) {
                        echo date('y-m-d H:i:s') . '  - ' . $filename . '    ';
                    }

                    $remoteFilename = $task['path']($filename);

                    if ($type === 1) {
                        $result = ssh2_sftp_unlink($task['ftp'], $remoteFilename);
                    } else {
                        $result = ssh2_sftp_rmdir($task['ftp'], $remoteFilename);
                    }

                    if (!$result) {
                        $needPing = true;
                    }

                    if ($verbose) {
                        echo $result ? 'ok' : 'FAIL', PHP_EOL;
                    }
                }

                foreach ($task['modified'] as $filename) {
                    if (isset($changesMap[$filename]) && $changesMap[$filename] >= $timeFrom) {
                        continue;
                    }

                    if ($verbose) {
                        echo date('y-m-d H:i:s') . '  * ' . $filename . '    ';
                    }

                    $changesMap[$filename] = $time;
                    $remoteFilename = $task['path']($filename);

                    usleep(10);
                    $result = ssh2_scp_send($task['ssh'], $filename, $remoteFilename);

                    if (!$result) {
                        $needPing = true;
                    }

                    if ($verbose) {
                        echo $result ? 'ok' : 'FAIL', PHP_EOL;
                    }
                }
            }

            // save new time to timeFrom
            $timeFrom = $time;

            // sleep interval
            usleep($interval);

            // garbage collector
            if (rand(0, $gcTick) === 0) {
                gc_collect_cycles();
                $garbageTime = $time - 60;
                $c = 0;
                foreach ($changesMap as $filename => $mtime){
                    if ($mtime < $garbageTime) {
                        unset($changesMap[$filename]);
                        ++$c;
                    }
                }

                if ($verbose) {
                    echo "* gc collected: {$c}", PHP_EOL;
                }
            }
        }
    }

    /**
     * Creates new templates to push and pull
     *
     * - adds template to local storage or global storage
     */
    protected function doTemplate()
    {
        $options = $this->parseArgs('hv', ['exclude:', 'global']);

        if (isset($options['h']) || is_string($options['arg0'])) {
            echo
                "Syntax: dcync template name path+ [--exclude=path]* [--global]", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " name          template name", PHP_EOL,
                " path          operation paths", PHP_EOL,
                " --exclude     excluding paths", PHP_EOL,
                " --global      save to global config", PHP_EOL,
                " -v            extended output", PHP_EOL,
                " -h            prints this help", PHP_EOL,
                PHP_EOL
            ;
            return;
        }

        array_shift($options['arg0']);

        $name     = trim(array_shift($options['arg0']));
        $paths    = $options['arg0'];
        $global   = isset($options['global']);
        $verbose  = isset($options['v']);
        $excludes =
            isset($options['exclude'])
            ? ( is_array($options['exclude']) ? $options['exclude'] : [ $options['exclude'] ] )
            : []
        ;

        $template = new stdClass;
        $template->paths = $paths;
        $template->excludes = $excludes;

        // validate name

        if (!$name) {
            throw new Exception('template name is empty');
        }

        if (file_exists($name)) {
            throw new Exception('template name is like a file');
        }

        // store to config

        if ($verbose) {
            echo "* saving template:", PHP_EOL;
            print_r($template);
        }

        if ($global) {
            $config = $this->getGlobalConfig();
            if (!$config->templates) {
                $config->templates = new stdClass;
            }
            $config->templates->{$name} = $template;
            $this->setGlobalConfig($config);
        } else {
            $config = $this->getLocalConfig();
            if (!$config->templates) {
                $config->templates = new stdClass;
            }
            $config->templates->{$name} = $template;
            $this->setLocalConfig($config);
        }

        echo "Ok, can be dcynced as \"{$name}\"" . ($global ? ' globally' : '') . " !", PHP_EOL;
    }

    /**
     * Pushes paths to remote
     *
     * - builds & executes rsync command
     */
    protected function doPush()
    {
        $options = $this->parseArgs('hv', ['exclude:']);

        if (isset($options['h']) || is_string($options['arg0'])) {
            echo
                "Syntax: ", PHP_EOL,
                PHP_EOL,
                "  dcync push path+ [--exclude=path]*", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " path          paths to pushing", PHP_EOL,
                " --exclude     excluding paths", PHP_EOL,
                " -v            extended output", PHP_EOL,
                " -h            prints this help", PHP_EOL,
                PHP_EOL,
                PHP_EOL,
                "  dcync push template-name", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " template-name template name", PHP_EOL,
                PHP_EOL
            ;
            return;
        }

        array_shift($options['arg0']);

        $paths    = $options['arg0'];
        $verbose  = isset($options['v']);
        $excludes =
            isset($options['exclude'])
            ? ( is_array($options['exclude']) ? $options['exclude'] : [ $options['exclude'] ] )
            : []
        ;

        $localConfig  = $this->getLocalConfig();
        $globalConfig = $this->getGlobalConfig();

        // select configuration

        if (count($paths) === 1 && isset($localConfig->templates->{ current($paths) })) {
            $template = $localConfig->templates->{ current($paths) };
            $paths    = $template->paths;
            $excludes = $template->excludes;

        } elseif (count($paths) === 1 && isset($globalConfig->templates->{ current($paths) })) {
            $template = $globalConfig->templates->{ current($paths) };
            $paths    = $template->paths;
            $excludes = $template->excludes;

        }

        // filter paths

        if (!$paths) {
            throw new Exception('wrong paths');
        }

        $baseDir = realpath('.');
        $relativePaths = [];
        foreach ($paths as $path) {
            $realPath = realpath($path);
            if ($realPath === false) {
                throw new Exception('file ' . $realPath . ' did not exist');
            }
            if (strpos($realPath, $baseDir) !== 0) {
                throw new Exception('file ' . $realPath . ' was not in project dir');
            }
            if (strlen($baseDir) === strlen($realPath)) {
                $relativePaths[] = '/.';
            } else {
                $relativePaths[] = substr($realPath, strlen($baseDir));
            }
        }

        // prepare rsync commands

        $userhost = $localConfig->remote->user . '@' . $localConfig->remote->host;

        $excludeArgs = [];
        foreach ($excludes as $exclude) {
            $excludeArgs[] = '--exclude ' . escapeshellarg($exclude);
        }

        $commands = [];
        foreach ($relativePaths as $relativePath) {
            if ($relativePath === '.') {
                $remotePath = $userhost . ':' . $localConfig->remote->path . $relativePath . '/';
            } else {
                $remotePath = $userhost . ':' . dirname($localConfig->remote->path . $relativePath) . '/';
            }
            $commands[$relativePath] = escapeshellarg('.' . $relativePath) . ' ' . escapeshellarg($remotePath) . ' ' . implode(' ', $excludeArgs);
        }

        // dry run

        foreach ($commands as $relativePath => $command) {
            $cmd = 'rsync -a --dry-run --out-format="%n" --delete ' . $command;

            if ($verbose) {
                echo "* executing " . $cmd, PHP_EOL;
            }

            $lines = [];
            exec($cmd, $lines);

            if ($verbose) {
                echo
                    "* received: ", PHP_EOL,
                    implode(PHP_EOL, $lines),
                    PHP_EOL
                ;
            }

            if (count($lines) > 12) {
                $prompt = "The path " . $relativePath . " provides " . count($lines) . " changes, continue (y/n)? ";
                if (trim(readline($prompt)) !== 'y') {
                    unset($commands[$relativePath]);
                }
            }
        }

        // real run

        foreach ($commands as $relativePath => $command) {
            $cmd = 'rsync -a --out-format="%n" --delete ' . $command;

            if ($verbose) {
                echo "* executing " . $cmd, PHP_EOL;
            }

            $lines = [];
            exec($cmd, $lines);

            if ($verbose) {
                $date = date('y-m-d H:i:s');
                foreach ($lines as $line) {
                    echo $date . '  -> ' . $line . PHP_EOL;
                }
            }
        }

        // end message

        echo "Ok, dcynced!", PHP_EOL;
    }

    /**
     * Pulls paths from remote
     *
     * - builds & executes rsync command
     */
    protected function doPull()
    {
        $options = $this->parseArgs('hv', ['exclude:']);

        if (isset($options['h']) || is_string($options['arg0'])) {
            echo
                "Syntax: ", PHP_EOL,
                PHP_EOL,
                "  dcync pull path+ [--exclude=path]*", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " path          paths to pushing", PHP_EOL,
                " --exclude     excluding paths", PHP_EOL,
                " -v            extended output", PHP_EOL,
                " -h            prints this help", PHP_EOL,
                PHP_EOL,
                PHP_EOL,
                "  dcync pull template-name", PHP_EOL,
                PHP_EOL,
                "Options:", PHP_EOL,
                " template-name template name", PHP_EOL,
                PHP_EOL
            ;
            return;
        }

        array_shift($options['arg0']);

        $paths    = $options['arg0'];
        $verbose  = isset($options['v']);
        $excludes =
            isset($options['exclude'])
            ? ( is_array($options['exclude']) ? $options['exclude'] : [ $options['exclude'] ] )
            : []
        ;

        $localConfig  = $this->getLocalConfig();
        $globalConfig = $this->getGlobalConfig();

        // select configuration

        if (count($paths) === 1 && isset($localConfig->templates->{ current($paths) })) {
            $template = $localConfig->templates->{ current($paths) };
            $paths    = $template->paths;
            $excludes = $template->excludes;

        } elseif (count($paths) === 1 && isset($globalConfig->templates->{ current($paths) })) {
            $template = $globalConfig->templates->{ current($paths) };
            $paths    = $template->paths;
            $excludes = $template->excludes;

        }

        // filter paths

        if (!$paths) {
            throw new Exception('wrong paths');
        }

        $baseDir = realpath('.');
        $relativePaths = [];
        foreach ($paths as $path) {
            $realPath = rtrim($this->resolveFilename($baseDir . '/' . $path), '/');
            if (!$realPath) {
                throw new Exception('path was empty');
            }
            if (strpos($realPath, $baseDir) !== 0) {
                throw new Exception('file ' . $realPath . ' was not in project dir');
            }
            $relativePaths[] = substr($realPath, strlen($baseDir));
        }

        // prepare rsync commands

        $userhost = $localConfig->remote->user . '@' . $localConfig->remote->host;

        $excludeArgs = [];
        foreach ($excludes as $exclude) {
            $excludeArgs[] = '--exclude ' . escapeshellarg($exclude);
        }

        $commands = [];
        foreach ($relativePaths as $relativePath) {
            $remotePath = $userhost . ':' . $localConfig->remote->path . $relativePath;
            $localPath = dirname('.' . $relativePath);
            $commands[$relativePath] = escapeshellarg($remotePath) . ' ' . escapeshellarg($localPath) . ' ' . implode(' ', $excludeArgs);
        }

        // dry run

        foreach ($commands as $relativePath => $command) {
            $cmd = 'rsync -a --dry-run --out-format="%n" --delete ' . $command;

            if ($verbose) {
                echo "* executing " . $cmd, PHP_EOL;
            }

            $lines = [];
            exec($cmd, $lines);

            if ($verbose) {
                echo
                    "* received: ", PHP_EOL,
                    implode(PHP_EOL, $lines),
                    PHP_EOL
                ;
            }

            if (count($lines) > 12) {
                $prompt = "The path " . $relativePath . " provides " . count($lines) . " changes, continue (y/n)? ";
                if (trim(readline($prompt)) !== 'y') {
                    unset($commands[$relativePath]);
                }
            }
        }

        // real run

        foreach ($commands as $relativePath => $command) {
            $cmd = 'rsync -a --out-format="%n" --delete ' . $command;

            if ($verbose) {
                echo "* executing " . $cmd, PHP_EOL;
            }

            $lines = [];
            exec($cmd, $lines);

            if ($verbose) {
                $date = date('y-m-d H:i:s');
                foreach ($lines as $line) {
                    echo $date . '  <- ' . $line . PHP_EOL;
                }
            }
        }

        // end message

        echo "Ok, dcynced!", PHP_EOL;
    }


    ///////////////////////// HELPERS  /////////////////////////

    /**
     * Parses args using getopt()
     *
     * @param string $short Each character in this string will be used as option characters and matched against options passed to the script starting with a single hyphen (<i>-</i>).   For example, an option string <i>"x"</i> recognizes an option <i>-x</i>.   Only a-z, A-Z and 0-9 are allowed.
     * @param array $long   An array of options. Each element in this array will be used as option strings and matched against options passed to the script starting with two hyphens (<i>--</i>).   For example, an longopts element <i>"opt"</i> recognizes an option <i>--opt</i>.
     * @return array <p>This function will return an array of option / argument pairs, or <b><code>FALSE</code></b> on failure.</p><p><b>Note</b>:</p><p>The parsing of options will end at the first non-option found, anything that follows is discarded.</p>
     */
    protected function parseArgs($short, $long = [])
    {
        global $argv;

        // slice options

        $opts = [];
        $args = [];

        foreach ($argv as $k => $v) {
            if ($k === 0) {
                continue;
            }

            if ($opts || $v{0} === '-') {
                $opts[] = $v;
            } else {
                $args[] = $v;
            }
        }

        // build microscript args

        $microArgs = '';

        if ($args) {
            $microArgs .= ' --arg0 ' . implode(' --arg0 ', array_map('escapeshellarg', $args));
        }

        if ($opts) {
            $microArgs .= ' ' . implode(' ', array_map('escapeshellarg', $opts));
        }

        // run microscript

        $code =
            '$long = explode(",", getopt("h", ["args:", "arg0:"])["args"]);
            $short = array_shift($long);
            $long[] = "args:";
            $long[] = "arg0:";
            $opts = getopt($short, $long);
            unset($opts["args"]);
            echo json_encode($opts);';

        $cmd =
            'php -r ' . escapeshellarg($code) . ' -- '
                . ' --args ' . escapeshellarg($short . ($long ? ',' . implode(',', $long) : ''))
                . $microArgs
            ;

        $result = json_decode(shell_exec($cmd), true);

        return $result;
    }

    /**
     * Fetches global config
     *
     * @return object
     * @throws Exception
     */
    protected function getGlobalConfig()
    {
        try {
            $config = $this->loadConfig($this->homeDir . '/.dcync');
        } catch (Exception $e) {
            $config = new stdClass;
            $config->projects = [];
            $config->templates = new stdClass;
        }

        return $config;
    }

    /**
     * Fetches local config
     *
     * @param string $path
     * @return object
     * @throws Exception
     */
    protected function getLocalConfig($path = '.')
    {
        return $this->loadConfig($path . '/.dcync');
    }

    /**
     * Sets global config
     *
     * @param object $config
     * @throws Exception
     */
    protected function setGlobalConfig($config)
    {
        return $this->saveConfig($this->homeDir . '/.dcync', $config);
    }

    /**
     * Sets local config
     *
     * @param object $config
     * @throws Exception
     */
    protected function setLocalConfig($config)
    {
        return $this->saveConfig('.dcync', $config);
    }

    /**
     * Sets local config
     *
     * @param string $path
     * @throws Exception
     */
    protected function removeLocalConfig($path = '.')
    {
        if (file_exists($path . '/.dcync')) {
            if (!unlink($path . '/.dcync')) {
                throw new Exception('error on removing config');
            }

        } else {
            throw new Exception('config was not found');
        }
    }

    /**
     * Loads config from file
     *
     * @param string $filename
     * @return object
     * @throws Exception
     */
    protected function loadConfig($filename)
    {
        if (!is_readable($filename)) {
            throw new Exception('config was not found: ' . $filename);
        }

        $result = json_decode(file_get_contents($filename));

        if ($result === null) {
            throw new Exception('invalid config file: ' . $filename);
        }

        return $result;
    }

    /**
     * Saves config to file
     *
     * @param string $filename
     * @param object $config
     * @throws Exception
     */
    protected function saveConfig($filename, $config)
    {
        if (!is_writable(dirname($filename))) {
            throw new Exception('config was not writable: ' . $filename);
        }

        $result =
            file_put_contents(
                $filename,
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            )
        ;

        if ($result === false) {
            throw new Exception('error on saving config: ' . $filename);
        }
    }

    /**
     * Init ssh connection
     *
     * @param string $user
     * @param string $host
     * @return resource
     * @throws Exception
     */
    protected function getSSHConnect($user, $host)
    {
        $session = ssh2_connect($host);

        if (!$session) {
            throw new Exception('ssh error to: ' . $host);
        }

        if (!ssh2_auth_pubkey_file(
            $session,
            $user,
            $this->homeDir . '/.ssh/id_rsa.pub',
            $this->homeDir . '/.ssh/id_rsa'
        )) {
            throw new Exception('ssh auth error to: ' . $host);
        }

        return $session;
    }

    /**
     * Walks in array tree and searches modifications (diff)
     *
     * @param string $path
     * @param int $timeFrom
     * @param array $exclude
     * @param array $fileList
     * @param array $modifiedList
     */
    protected function walkTree($path, $timeFrom, array $exclude, array &$fileList, array &$modifiedList)
    {
        $ff = scandir($path, SCANDIR_SORT_NONE);

        if ($ff === false) {
            throw new Exception;
        }

        foreach ($ff as $f)
        {
            if ($f === '.' || $f === '..')
            {
                continue;
            }

            if (isset($exclude[$f]))
            {
                continue;
            }

            $filename = $path . '/' . $f;

            if (is_link($filename))
            {
                // symlink is not supported
                continue;
            }

            if (!file_exists($filename))
            {
                // if removed after scan
                continue;
            }

            if (!is_dir($filename))
            {
                $fileList[$filename] = 1;

                if (filemtime($filename) >= $timeFrom)
                {
                    $modifiedList[] = $filename;
                }
            }
            else
            {
                $fileList[$filename] = 2;
                $this->walkTree($filename, $timeFrom, $exclude, $fileList, $modifiedList);
            }
        }
    }

    /**
     * Sanitizes path like realpath
     *
     * @param string $filename
     * @return string
     */
    protected function resolveFilename($filename)
    {
        $filename = str_replace('//', '/', $filename);
        $parts = explode('/', $filename);
        $out = array();
        foreach ($parts as $part){
            if ($part == '.') {
                continue;
            }
            if ($part == '..') {
                array_pop($out);
                continue;
            }
            $out[] = $part;
        }
        return implode('/', $out);
    }
}

(new dcync())->main();
