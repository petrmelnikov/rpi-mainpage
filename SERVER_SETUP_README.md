# Настройка сервера

Актуальная установка Docker-версии проекта с нуля находится в [README.md](README.md).

Краткий сценарий после установки Docker Engine и Compose plugin:

```bash
git clone https://github.com/petrmelnikov/rpi-mainpage.git /apps/rpi-mainpage
cd /apps/rpi-mainpage
cp .env.example .env
./scripts/server-install.sh
```

Старый `systemd_install.sh` относится к прежнему запуску через встроенный PHP-сервер. Он не устанавливает Nginx, `jellyfin-ffmpeg`, HLS worker или устройства RK3588 и не должен использоваться для текущей конфигурации.
