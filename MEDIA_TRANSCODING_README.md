# Совместимое с браузером транскодирование видео

Для форматов, которые браузер поддерживает самостоятельно, File Index сохраняет обычный HTTP Range endpoint `/file-index/stream`. Если контейнер или кодеки не поддерживаются, приложение создаёт приватную HLS-сессию и использует `jellyfin-ffmpeg` внутри контейнера `app`.

Jellyfin Server не устанавливается и не является runtime-зависимостью.

## Установка на Orange Pi 5 Plus

Полная установка описана в [README.md](README.md):

```bash
cp .env.example .env
./scripts/server-install.sh
```

Скрипты автоматически подключают `docker-compose.opi.yml`, если найдены `/dev/mpp_service`, `/dev/rga` и `/dev/mali0`. Явное управление находится в `.env`:

```dotenv
RPI_MAINPAGE_USE_OPI=auto
```

Аппаратный override передаёт в `app`:

- `/dev/dri`;
- `/dev/dma_heap`;
- `/dev/mali0`;
- `/dev/rga`;
- `/dev/mpp_service`.

При старте контейнер добавляет PHP-FPM пользователя в группы фактических GID этих устройств. Это учитывает различия групп между Ubuntu/Armbian на хосте и Debian внутри контейнера.

Образ закрепляет `jellyfin-ffmpeg 7.1.4-3` для arm64/amd64 и проверяет SHA256 официальных Debian-пакетов. На arm64 также устанавливается закреплённый Mali G610 userspace runtime для OpenCL tone mapping. Для обновления необходимо одновременно изменить версию и контрольные суммы в `docker/php/Dockerfile`; неверная сумма останавливает сборку.

## Проверка установки

```bash
./scripts/server-check.sh
```

Дополнительные команды:

```bash
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app ffmpeg -version
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app sh -lc 'ffmpeg -hide_banner -encoders 2>&1 | grep rkmpp'
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app sh -lc 'ffmpeg -hide_banner -filters 2>&1 | grep rkrga'
docker compose -f docker-compose.yml -f docker-compose.opi.yml exec app ls -l /dev/mpp_service /dev/rga /dev/dri /dev/mali0
```

Video Info и HLS используют одни и те же `/usr/local/bin/ffmpeg` и `/usr/local/bin/ffprobe`, указывающие на `/usr/lib/jellyfin-ffmpeg`. Параллельного Debian FFmpeg в образе нет.

## Выбор режима воспроизведения

1. Браузер запрашивает `/file-index/playback-plan`; `ffprobe` определяет контейнер, кодеки, профиль, bit depth, HDR и длительность.
2. JavaScript вызывает `canPlayType()` с MIME и codec string.
3. Поддерживаемое видео остаётся на прямом Range stream.
4. Для неподдерживаемого видео вызывается `/file-index/transcode/start`.
5. Safari/iOS/iPadOS использует встроенный HLS; остальные браузеры — закреплённый `hls.js`.
6. Если прямое воспроизведение было ошибочно признано доступным, событие `video.error` один раз переключает плеер на HLS.

Если Safari сообщает поддержку HEVC и HDR, сервер сохраняет исходный HEVC Main10/HDR10, выставляет тег `hvc1` для Apple HLS и при необходимости преобразует только звук в AAC. Для SDR-клиента используется аппаратная цепочка `RKMPP decode → RKRGA P010 → OpenCL tone map → RKMPP H.264 encode`.

Сервер выбирает один из режимов:

- `remux` — видео и AAC копируются, меняется только контейнер;
- `audio-transcode` — видео копируется, звук преобразуется в AAC;
- `hardware-transcode` — RKMPP декодирует и кодирует H.264, RKRGA используется при наличии;
- `software-transcode` — резервный `libx264` и AAC.

При ошибке RKMPP worker автоматически повторяет текущий пакет через `libx264` и сохраняет причину в `fallbackReason`.

## HLS worker

PHP-FPM не удерживает FFmpeg внутри HTTP-запроса. Контроллер запускает отдельный CLI worker через:

```text
/usr/local/bin/php /app/scripts/media-transcode-worker.php
```

Worker создаёт ограниченные группы fMP4-сегментов, поддерживает запрос отсутствующего сегмента после seek, использует блокировки сессии и завершает работу после idle timeout.

Каталог сессии:

```text
/media/.rpi-mainpage-data/transcodes/<session-id>/
```

Содержимое:

- `state.json` — состояние, выбранный и фактический режим, PID, очередь и ошибка;
- `worker-launch.log` — stdout/stderr detached worker до инициализации состояния;
- `worker.lock`, `state.lock` — защита от параллельного запуска;
- `command.jsonl` — журнал argv FFmpeg;
- `ffmpeg.log` — stderr FFmpeg;
- `init-*.mp4`, `segment-*.m4s` — готовые HLS-артефакты.

Nginx может читать каталоги сессий, но URL `/_internal/transcodes/` объявлен `internal`. Клиент получает файл только после проверки session ID и имени артефакта PHP-контроллером.

## Настройки

Изменяемые значения находятся в `.env`:

- `MEDIA_H264_BITRATE=6000k`;
- `MEDIA_HLS_SEGMENT_SECONDS=4`;
- `MEDIA_HLS_BATCH_SEGMENTS=4`;
- `MEDIA_SEGMENT_WAIT_SECONDS=30`;
- `MEDIA_WORKER_IDLE_SECONDS=15`;
- `MEDIA_MAX_SESSIONS=3`;
- `MEDIA_SESSION_TTL_SECONDS=1800`.

Внутренние пути контейнера задаются в `docker-compose.yml` и обычно не требуют изменения:

- `MEDIA_FFMPEG_BIN=/usr/local/bin/ffmpeg`;
- `MEDIA_FFPROBE_BIN=/usr/local/bin/ffprobe`;
- `MEDIA_PHP_CLI_BIN=/usr/local/bin/php`;
- `MEDIA_TRANSCODE_DIR=/media/.rpi-mainpage-data/transcodes`.

## Диагностика проблем

Сначала выполните:

```bash
./scripts/server-check.sh
./scripts/server-logs.sh
```

Если плеер создал сессию, но она остаётся в `queued`:

```bash
find /media/.rpi-mainpage-data/transcodes -name state.json -exec grep -H -E '"status"|"workerPid"|"error"' {} \;
```

Затем откройте `worker-launch.log` соответствующей сессии. Если статус `failed`, проверьте `error`, `fallbackReason` и `ffmpeg.log`.

Проверить реальную загрузку блоков RK3588 во время воспроизведения:

```bash
sudo sh -c 'echo 1000 > /proc/mpp_service/load_interval'
sudo watch -n 1 cat /proc/mpp_service/load
```

В отдельном терминале:

```bash
sudo watch -n 1 cat /sys/kernel/debug/rkrga/load
```

## Ограничения

- Используется первая видео- и первая аудиодорожка.
- Субтитры в compatibility HLS пока не передаются.
- Точные границы remux зависят от ключевых кадров исходного видео.
- Независимые on-demand группы используют discontinuity; seek не требует обработки всего файла.
- Реальные RKMPP/RGA/OpenCL комбинации зависят от ядра и драйверов конкретного Orange Pi.

Локальные структурные проверки:

```bash
php scripts/test-media-transcoding.php
docker compose config
docker compose -f docker-compose.yml -f docker-compose.opi.yml config
```
