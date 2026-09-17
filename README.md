# RPI Mainpage

Домашняя веб-панель и файловый каталог для Orange Pi. Продакшен-запуск состоит из двух Docker-контейнеров:

- `nginx` принимает HTTP-запросы на порту 80;
- `app` запускает PHP-FPM, выполняет операции с хостом через выделенное SSH-соединение и воспроизводит видео;
- поддерживаемое браузером видео отдаётся напрямую с HTTP Range;
- неподдерживаемое видео преобразуется в HLS через единый `jellyfin-ffmpeg`;
- на Orange Pi 5 Plus используются RKMPP/RKRGA и устройства RK3588.

Jellyfin Server приложению не нужен. В контейнер устанавливается только закреплённая сборка `jellyfin-ffmpeg`.

## Системные утилиты на хосте

В Docker-режиме `SHELL_OVER_SSH=1`: системные команды выполняются на `SSH_REMOTE_HOST` от имени `SSH_REMOTE_USER` (по умолчанию `ubuntu`). Пакеты для этих команд устанавливаются на SSH-хосте. Docker и утилиты `scripts/server-*.sh` нужны на машине, где развёрнут проект; при обычной установке это тот же Orange Pi.

### Основные зависимости и обслуживание

| Команды / служба | Пакет Ubuntu/Debian | Назначение |
| --- | --- | --- |
| Docker Engine, `docker`, `docker compose` | `docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin` из репозитория Docker | Сборка, запуск, обновление и пересборка контейнеров. Серверные скрипты требуют Compose plugin. |
| `sshd` | `openssh-server` | Принимает SSH-соединения из контейнера; должен быть доступен через Docker bridge. |
| `ssh`, `ssh-keygen` | `openssh-client` | Настройка ключа контейнера и обновление Git-репозитория через SSH. |
| `bash`, `sh` | `bash`, `dash` | `bash -s` для команд по SSH, серверные скрипты и фоновые задачи. |
| `df`, `head`, `cat`, `readlink`, `nohup` | `coreutils` | Свободное место, чтение датчиков и фоновая пересборка. |
| `base64`, `tr`, `dirname`, `basename`, `cp`, `mkdir`, `chmod`, `chown`, `touch`, `install`, `tail`, `sleep` | `coreutils` | Установка, работа с путями и ключами, права каталогов, просмотр логов. Обычно уже установлены. |
| `grep` | `grep` | Фильтрация системной информации, показаний дисков и проверки установки. |
| `landscape-sysinfo` | `landscape-common` | Сводка CPU, памяти и нагрузки на главной странице. |
| `top`, `ps` | `procps` | Страница процессов и поток `/top/live`; `ps` используется как резервный вариант. |
| `git` | `git` | Клонирование, обновление через веб-интерфейс и `server-update.sh`. |
| `sudo`, `visudo` | `sudo` | Доступ к SMART и настройка прав; запуск Docker с повышением прав, если это требуется. |
| `hostname`, `awk` | `hostname`, `mawk` (или `gawk`) | Вывод адреса сервера в `server-start.sh` и `server-check.sh`. |
| `systemctl`, `journalctl` | `systemd` | Управление службами SSH/Docker и их журналами в Ubuntu/Armbian. |
| `apt` | `apt` | Установка системных пакетов по этой инструкции. |

`printf`, `echo`, `read`, `command`, `test`/`[`, `cd`, `pwd` и `export` доступны как встроенные команды оболочки. Для HTTPS-загрузок и Git по HTTPS нужен пакет сертификатов `ca-certificates`.

### Зависимости отдельных функций

| Команда | Пакет | Когда нужна на хосте |
| --- | --- | --- |
| `smartctl` | `smartmontools` | Температура SATA, SCSI, NVMe и поддерживаемых USB-дисков через SMART. |
| `nvme` | `nvme-cli` | Дополнительный источник температуры нативных NVMe через `nvme smart-log`. |
| `aria2c`, `wget` | `aria2`, `wget` | Резервное скачивание URL по SSH: сначала приложение пробует обе утилиты внутри контейнера, затем на хосте. Для этого резерва достаточно одного рабочего загрузчика; установка обоих сохраняет оба варианта. |
| `curl` | `curl` | HTTP-проверка в `server-check.sh`; без него эта проверка пропускается. |
| `composer`, `php` | `composer`, `php-cli`, `php-zip`; `php-mbstring` для Composer и корректной работы с Unicode | Веб-действие `/update-code` запускает Composer на хосте, если он установлен. Без него при существующем `vendor/` установка зависимостей пропускается. Для полного обновления через эту кнопку нужен хостовый PHP с расширениями из `composer.json`; `scripts/server-update.sh` выполняет Composer внутри контейнера. JSON встроен в PHP 8. |
| `docker-compose` | `docker-compose` | Только старый резервный путь веб-действия пересборки. При наличии Compose plugin устанавливать отдельно не требуется; серверные скрипты этот резервный путь не используют. |

Чтение температуры из `/sys/class/hwmon` не требует `lm-sensors`, `hddtemp`, `nvme-cli` или `smartmontools`. Нужны доступные для чтения датчики ядра `nvme`/`drivetemp`; при их отсутствии используются установленные SMART-утилиты. Наличие пакета само по себе не гарантирует, что USB-адаптер пропускает SMART-команды.

PHP-FPM/CLI, Composer для серверных скриптов, `jellyfin-ffmpeg`/`ffprobe`, `setsid` (`util-linux`), `tar`/`gzip`, `unzip`, а также основные загрузчики `aria2c`/`wget` уже предоставляются образом. Архивирование каталога, очередь загрузок и HLS worker запускаются внутри контейнера. Для аппаратного видео хост должен предоставлять ядро и устройства RK3588, перечисленные ниже.

При запуске **без Docker** через `scripts/dev-start.sh` эти контейнерные зависимости переходят на локальную машину: PHP 8.2+ с JSON/ZIP, Composer, `tar`, `gzip`, загрузчик `aria2c` или `wget`, а для HLS — подходящий FFmpeg/FFprobe и Linux `setsid` из `util-linux`. `php-mbstring` улучшает поиск и обработку Unicode. Пути `MEDIA_PHP_CLI_BIN`, `MEDIA_FFMPEG_BIN`, `MEDIA_FFPROBE_BIN` должны указывать на локальные исполняемые файлы; Linux SMART/sysfs и аппаратный RKMPP на macOS недоступны. Старый `systemd_install.sh` дополнительно вызывает `sed` (пакет `sed`) и `systemctl`, но его шаблон службы отсутствует: для текущей установки используйте Docker-скрипты.

## Установка на Orange Pi с нуля

Инструкция рассчитана на 64-битную Ubuntu/Armbian и каталог проекта `/apps/rpi-mainpage`.

### 1. Подготовить систему

Установите хостовые утилиты для системной информации, температуры SSD, обслуживания и резервного скачивания. Команда рассчитана на Ubuntu/Armbian с репозиториями Ubuntu; для Debian проверьте доступность `landscape-common` в выбранном выпуске:

```bash
sudo apt update
sudo apt install -y \
  git openssh-server openssh-client sudo ca-certificates \
  bash dash coreutils grep procps hostname mawk \
  landscape-common smartmontools nvme-cli aria2 wget curl
sudo systemctl enable --now ssh
```

Установите Docker Engine и Compose plugin из официального репозитория Docker:

- [Docker Engine для Ubuntu](https://docs.docker.com/engine/install/ubuntu/)
- [настройка запуска Docker без sudo](https://docs.docker.com/engine/install/linux-postinstall/)

После установки проверьте:

```bash
docker version
docker compose version
```

Если пользователь был добавлен в группу `docker`, переподключитесь по SSH перед продолжением.

### 2. Проверить устройства RK3588

```bash
ls -ld \
  /dev/dri \
  /dev/dma_heap \
  /dev/mali0 \
  /dev/rga \
  /dev/mpp_service
```

Если `/dev/mpp_service`, `/dev/rga` или `/dev/mali0` отсутствуют, аппаратное транскодирование не заработает до установки подходящего ядра и драйверов Rockchip. Справочная документация: [Rockchip VPU в Jellyfin](https://jellyfin.org/docs/general/post-install/transcoding/hardware-acceleration/rockchip/).

### 3. Клонировать проект

```bash
sudo install -d -o "$USER" -g "$USER" /apps/rpi-mainpage
git clone https://github.com/petrmelnikov/rpi-mainpage.git /apps/rpi-mainpage
cd /apps/rpi-mainpage
```

При установке конкретной ветки переключите её до запуска установщика:

```bash
git switch <branch>
```

### 4. Настроить `.env`

```bash
cp .env.example .env
nano .env
```

Для текущей структуры Orange Pi оставьте:

```dotenv
HOST_MEDIA_ROOT=/media
RPI_MAINPAGE_USE_OPI=auto
```

`HOST_MEDIA_ROOT=/media` монтирует `/media` хоста в `/media` контейнера. Благодаря этому путь `/media/usb_ssd/downloads/movie.mkv` одинаков внутри Docker и на хосте, что важно для файлового каталога и SSH-команд.

Если носитель смонтирован в другом месте, предпочтительно примонтировать или связать его на хосте под `/media`, сохранив одинаковые абсолютные пути. Не указывайте в качестве корня только `/media/usb_ssd/downloads`, если в настройках каталога используются полные хостовые пути.

Основные параметры транскодирования уже имеют безопасные значения по умолчанию:

```dotenv
MEDIA_H264_BITRATE=6000k
MEDIA_HLS_SEGMENT_SECONDS=4
MEDIA_HLS_BATCH_SEGMENTS=4
MEDIA_MAX_SESSIONS=3
```

Для USB–NVMe адаптера, который `smartctl` не определяет автоматически, задайте тип **конкретного устройства на SSH-хосте**:

```dotenv
SSD_SMARTCTL_DEVICE_TYPES='{"/dev/sda":"sntjmicron","/dev/sdb":"sntrealtek"}'
```

Поддерживаются `sat`, `scsi`, `sntjmicron`, `sntrealtek`, `sntasmedia`. Соответствия мостов описаны в [документации smartctl](https://github.com/smartmontools/smartmontools/blob/main/src/smartctl.8.in). Значение по умолчанию — `{}`: автоматическое определение и резервный `-d sat` для `/dev/sd*`. Типы USB-мостов автоматически не перебираются. Сохраняйте внешние одинарные кавычки: `.env` читается как Docker Compose, так и shell-скриптами. Неверный JSON, путь или тип отображается как ошибка настройки в блоке температуры SSD. После изменения `.env` пересоздайте контейнеры через `./scripts/server-restart.sh`; при локальном запуске задавайте переменную через `export` перед `scripts/dev-start.sh`.

### Права на команды хоста

SSH-пользователь должен иметь права на чтение/запись каталога проекта и нужных каталогов `/media`, а для веб-пересборки — доступ к Docker. Сопоставление владельцев файлов с пользователем контейнера по умолчанию использует UID/GID `1000:1000`.

Для `smartctl` и `nvme` приложение сначала пробует `sudo -n`, затем запуск без sudo. Если чтение диска требует root, разрешите SSH-пользователю только нужные команды без пароля. Пример для пользователя `ubuntu`, нативного NVMe `/dev/nvme0n1` и USB-диска `/dev/sda` с мостом JMicron:

```bash
sudo visudo -f /etc/sudoers.d/rpi-mainpage-smart
```

```sudoers
ubuntu ALL=(root) NOPASSWD: /usr/sbin/nvme smart-log /dev/nvme0n1
ubuntu ALL=(root) NOPASSWD: /usr/sbin/smartctl -A /dev/nvme0n1
ubuntu ALL=(root) NOPASSWD: /usr/sbin/smartctl -d sntjmicron -A /dev/sda
```

Замените пользователя, пути утилит и устройств на свои. Для SATA без явного типа нужны правила для `/usr/sbin/smartctl -A /dev/sda` и `/usr/sbin/smartctl -d sat -A /dev/sda`. Затем проверьте от имени того же SSH-пользователя:

```bash
sudo -n /usr/sbin/nvme smart-log /dev/nvme0n1
sudo -n /usr/sbin/smartctl -d sntjmicron -A /dev/sda
```

Для обычных команд хоста нужен `PATH` с `/usr/bin:/bin`; сборщик температуры также добавляет `/usr/sbin:/sbin`. Чтение доступных sysfs-датчиков не требует sudo. Установка пакетов не настраивает правила sudoers автоматически.

### 5. Запустить установщик

```bash
./scripts/server-install.sh
```

Установщик:

1. проверит Docker, Compose, каталог медиа и устройства RK3588;
2. создаст `/media/.rpi-mainpage-data/transcodes` с нужными правами;
3. предложит создать выделенный SSH-ключ контейнера;
4. проверит Compose-конфигурацию;
5. соберёт образ с закреплённым `jellyfin-ffmpeg` и Mali G610 runtime;
6. установит Composer-зависимости;
7. запустит контейнеры и выполнит диагностику.

При настройке SSH на том же Orange Pi подходят значения:

```text
Remote host for container: host.docker.internal
Remote port: 22
Remote user: ubuntu
Host used now for key provisioning: localhost
```

Закрытый ключ сохраняется только в игнорируемых Git файлах `.docker-ssh/` и `.env.ssh`.

### 6. Открыть приложение

```text
http://<IP-адрес-Orange-Pi>/
```

В настройках File Index задайте каталог, который одновременно существует на хосте и внутри контейнера, например:

```text
/media/usb_ssd/downloads
```

## Управление сервером

Все серверные скрипты автоматически используют `docker-compose.opi.yml`, когда обнаружены устройства RK3588. Ручное перечисление `-f docker-compose.yml -f docker-compose.opi.yml` не требуется.

```bash
./scripts/server-start.sh      # собрать при необходимости и запустить
./scripts/server-stop.sh       # остановить контейнеры
./scripts/server-restart.sh    # пересобрать и пересоздать контейнеры
./scripts/server-update.sh     # git pull, composer install, rebuild и restart
./scripts/server-check.sh      # проверить конфигурацию, FFmpeg и RKMPP
./scripts/server-logs.sh       # последние логи
./scripts/server-logs.sh -f    # следить за логами
```

Чтобы принудительно включить или выключить аппаратный override, измените `.env`:

```dotenv
RPI_MAINPAGE_USE_OPI=1
```

или:

```dotenv
RPI_MAINPAGE_USE_OPI=0
```

## Проверка видео

Общая диагностика:

```bash
./scripts/server-check.sh
```

Проверка кодеков вручную:

```bash
docker compose -f docker-compose.yml -f docker-compose.opi.yml \
  exec app sh -lc 'ffmpeg -hide_banner -encoders 2>&1 | grep rkmpp'

docker compose -f docker-compose.yml -f docker-compose.opi.yml \
  exec app sh -lc 'ffmpeg -hide_banner -filters 2>&1 | grep rkrga'
```

Сессии транскодирования находятся на хосте в:

```text
/media/.rpi-mainpage-data/transcodes/<session-id>/
```

Главные диагностические файлы:

- `state.json` — режим, PID и статус сессии;
- `worker-launch.log` — ошибки запуска фонового PHP worker;
- `ffmpeg.log` — сообщения FFmpeg;
- `command.jsonl` — фактически выбранные аргументы без shell-интерполяции.

Если `state.json` остаётся в `queued`, первым делом откройте `worker-launch.log`. Если RKMPP завершится ошибкой, worker автоматически повторит пакет через `libx264` и запишет причину в `fallbackReason`.

## Конфигурационные файлы

- `.env` — несекретные параметры Compose и транскодирования; не хранится в Git;
- `.env.ssh` — SSH endpoint и закрытый ключ; не хранится в Git;
- `.env.example` — шаблон безопасных значений;
- `docker-compose.yml` — базовые сервисы;
- `docker-compose.opi.yml` — устройства и настройки Orange Pi 5 Plus;
- `config/file_index.json` — путь каталога и закреплённые директории.

Подробности: [Docker и SSH](DOCKER_SSH_README.md), [транскодирование](MEDIA_TRANSCODING_README.md), [File Index](FILE_INDEX_README.md), [Hosted Tools](TOOLS_README.md).

## Локальная разработка

На macOS/Linux с установленными PHP и Composer:

```bash
./scripts/dev-start.sh
```

Приложение откроется по адресу `http://127.0.0.1:8080`. Аппаратный RKMPP в этом режиме не используется.

Регрессионные проверки температуры SSD запускаются без sudo, SSH и физических дисков: они используют временные файлы датчиков и подставные `smartctl`/`nvme`.

```bash
php scripts/test-ssd-temperature.php
```
