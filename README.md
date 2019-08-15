
# dcync

Приложение для быстрой синхронизации изменений в коде.

В отличие от привычных приложений синхронизации (rsync) и внутренней
синхронизации в IDE, dcync не делает лишней работы по сверке файлов,
благодаря чему синхронизация проходит максимально эффективно.

Приложение также позволяет обобщить работу по синхронизации, предоставляя набор
простых команд, запоминание и набор которых не составит трудностей.


## Установка

```
# cp dcync.php /usr/local/bin/dcync
```

## Схема работы

Чтобы начать работу с dcync, следует запустить `dcync run`.
Далее, в рабочих каталогах необходимо инициализировать окружение
синхронизации `dcync init`, после чего синхронизация начнет работу.

Параметры окружения можно менять вручную, в файле `.dcync`.

Также, находясь в рабочем каталоге, можно выполнять разовые операции с помощью
простого набора команд `dcync`.

### Команда run

Запуск пользовательского процесса быстрой синхронизации измененных файлов.
При этом, считываются все инициализированные каталоги, включая те, что были
инициализированы позже.

Пример:
```
$ dcync run -v
Ok, dcync is ready for changes ...
19-08-15 12:18:31  * /var/www/myapp/src/Controller/AppController.php
19-08-15 12:18:31  + /var/www/myapp/src/Controller/FrontController.php

### Команда init

Инициализация окружения синхронизации для текущего рабочего каталога.

Пример:
```
$ dcync init
Hi, let's configure your folder for dcync ;)
Remote directory: user@remote:/var/www/myapp
Folders/files to exclude:
- composer.lock
- vendor
- var
- .git

Ok, saved!
Hey, you can change this config later in .dcync!
```

### Команда destroy

Удаление синхронизации для текущего рабочего каталога.

Пример:
```
$ dcync destroy
Ok, destroyed!
```

### Команда push

Отправка файлов и папок на удаленную машину.

Пример:
```
$ cd /var/www/myapp
$ dcync push src/Controller/
Ok, dcynced!
```

### Команда pull

Скачивание файлов и папок с удаленной машины.

Пример:
```
$ cd /var/www/myapp
$ dcync pull src/Controller/
Ok, dcynced!
```

### Команда template

Добавление шаблона для последующего использования в push/pull.

Пример:
```
$ cd /var/www/myapp
$ dcync template composer composer.json composer.lock vendor
Ok, can be dcynced as "composer" !
$ dcync push composer
Ok, dcynced!
```

### Файл .dcync

```json
{
    "remote": "user@remote:/var/www/myapp",
    "exclude": [
        "composer.lock",
        "vendor",
        "var",
        ".git"
    ],
    "templates": {
        "all": {
            "paths": [
                "."
            ],
            "excludes": [
                "nbproject",
                ".git",
                "var"
            ]
        }
    }
}
```

### Файл $HOME/.dcync

```json
{
    "projects": [
        "/var/www/myapp",
        "/var/www/xxapp"
    ],
    "templates": {
        "composer": {
            "paths": [
                "composer.json",
                "composer.lock",
                "vendor"
            ],
            "excludes": []
        }
    }
}
```
