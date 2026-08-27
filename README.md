# RPI Mainpage

Домашняя веб-панель и файловый каталог для Orange Pi. Продакшен-запуск состоит из двух Docker-контейнеров:

- `nginx` принимает HTTP-запросы на порту 80;
- `app` запускает PHP-FPM, выполняет операции с хостом через выделенное SSH-соединение и воспроизводит видео;
- поддерживаемое браузером видео отдаётся напрямую с HTTP Range;
- неподдерживаемое видео преобразуется в HLS через единый `jellyfin-ffmpeg`;
- на Orange Pi 5 Plus используются RKMPP/RKRGA и устройства RK3588.

Jellyfin Server приложению не нужен. В контейнер устанавливается только закреплённая сборка `jellyfin-ffmpeg`.

## Установка на Orange Pi с нуля

Инструкция рассчитана на 64-битную Ubuntu/Armbian и каталог проекта `/apps/rpi-mainpage`.

### 1. Подготовить систему

Установите Git и SSH-сервер:

```bash
sudo apt update
sudo apt install -y git openssh-server
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
