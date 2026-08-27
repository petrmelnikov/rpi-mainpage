# Docker и SSH-мост к хосту

Основная установка описана в [README.md](README.md). Этот документ объясняет SSH-часть конфигурации.

## Зачем приложению SSH

PHP работает в контейнере, но некоторые системные и файловые операции должны выполняться на Orange Pi. Для них `app` открывает одно выделенное SSH-соединение к хосту и повторно использует его через OpenSSH ControlMaster.

Контейнер подключается к:

```text
host.docker.internal:22
```

В `docker-compose.yml` это имя направляется на Docker host gateway. SSH-сервер хоста должен слушать не только `127.0.0.1`.

## Первичная настройка

На Orange Pi выполните:

```bash
sudo systemctl enable --now ssh
cd /apps/rpi-mainpage
./scripts/setup-docker-ssh-key.sh
```

Для установки на том же сервере используйте ответы:

```text
Remote host for container: host.docker.internal
Remote port: 22
Remote user: ubuntu
Host used now for key provisioning: localhost
```

Последний адрес используется только один раз, чтобы добавить публичный ключ в `~/.ssh/authorized_keys`. Он отличается от адреса, по которому позднее подключается контейнер.

Скрипт создаёт:

- `.docker-ssh/id_ed25519` и публичный ключ;
- `.env.ssh` с endpoint, пользователем и закрытым ключом в base64;
- запись публичного ключа в `authorized_keys`, не удаляя существующие ключи.

Оба локальных файла исключены из Git.

## Параметры `.env.ssh`

```dotenv
SSH_REMOTE_HOST=host.docker.internal
SSH_REMOTE_PORT=22
SSH_REMOTE_USER=ubuntu
SSH_PRIVATE_KEY_B64=<generated-value>
```

Дополнительно можно добавить:

```dotenv
SSH_REMOTE_APP_DIR=/apps/rpi-mainpage
APP_RUN_USER=ubuntu
APP_RUN_GROUPS=www-data
```

Несекретные параметры предпочтительно хранить в `.env`, а не в `.env.ssh`.

## Как работает соединение

При старте `docker/php/init-ssh-and-run.sh`:

1. загружает `.env.ssh`;
2. восстанавливает ключ в `/tmp/ssh`;
3. создаёт OpenSSH-конфигурацию с `ControlMaster auto` и `ControlPersist 10m`;
4. прогревает соединение от имени PHP-FPM пользователя;
5. запускает PHP-FPM.

Последующие команды проходят через `/usr/local/bin/run-over-ssh.sh` и используют тот же control socket.

## Проверка

```bash
docker compose -f docker-compose.yml -f docker-compose.opi.yml \
  exec -T -u ubuntu app \
  ssh -F /tmp/ssh/config remote-target 'id && hostname'
```

Логи и общая диагностика:

```bash
./scripts/server-logs.sh
./scripts/server-check.sh
```

Если ключ или адрес изменились, повторно запустите `./scripts/setup-docker-ssh-key.sh`, затем `./scripts/server-restart.sh`.
