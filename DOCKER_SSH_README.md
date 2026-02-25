# Docker + Nginx + single SSH connection

This setup runs the app in Docker behind Nginx (port 80) and executes shell commands over one shared SSH connection.

## 1) Prepare key and env

```bash
./scripts/setup-docker-ssh-key.sh
```

What the script does:
- generates a dedicated Ed25519 key in `.docker-ssh/` (if missing)
- writes `.env.ssh` with `SSH_REMOTE_HOST`, `SSH_REMOTE_PORT`, `SSH_REMOTE_USER`, `SSH_PRIVATE_KEY_B64`
- appends the public key to remote `~/.ssh/authorized_keys` only if it is not already present (does not overwrite existing keys)

Optional variable in `.env.ssh`:
- `SSH_REMOTE_APP_DIR` — absolute path to this project on remote host (default: `/apps/rpi-mainpage`)

This path is used by the **pull** button and composer install commands.

## 2) Start

```bash
docker compose up --build
```

App is available at `http://localhost` (port `80` by default).

## File Index and host disks

`app` container mounts host media path:

- `${HOST_MEDIA_ROOT:-/media}:/media`

So host directories like `/media/usb_ssd/...` are visible in container at the same path.

If your files are not under `/media`, set env before start:

```bash
export HOST_MEDIA_ROOT=/your/host/path
docker compose up --build -d
```

## Runtime architecture

- `nginx` container serves HTTP on port `80`
- `app` container runs `php-fpm`
- Nginx forwards PHP requests to `app:9000`

The app is **not** started with `php -S`.

## How single SSH session works

Container init (`init-ssh-and-run.sh`) creates SSH config with:
- `ControlMaster auto`
- `ControlPersist 10m`
- `ControlPath /tmp/ssh/cm-%r@%h:%p`

Then it runs:

```bash
ssh -F /tmp/ssh/config -MNf remote-target
```

This opens one master connection. Subsequent command executions use `run-over-ssh.sh`, reusing the same control socket.

## Notes

- Keep `.env.ssh` and `.docker-ssh/` private.
- If remote host changes, rerun `./scripts/setup-docker-ssh-key.sh`.
