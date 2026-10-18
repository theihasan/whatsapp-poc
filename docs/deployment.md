# Deployment

Tick-box versions of this procedure: `docs/deployment-checklist.md` (pre-deployment, deployment, post-deployment, rollback triggers).

Target: one Ubuntu 24.04 VPS running Nginx, PHP 8.4-FPM, MySQL 8.4, Redis 7, Supervisor (Horizon, Reverb, gateway), cron (scheduler) and Node.js 22+ (24 LTS recommended for production; the gateway is tested on 22.22).

> **Not production-authorized.** A working deployment is not permission to use WhatsApp Web automation commercially. See `docs/security.md` → Compliance. The unofficial provider must stay disabled unless you use consenting test accounts; production messaging requires the official Cloud API with your own Meta authorization.

⛔ Neither the Docker stack nor the VPS procedure has been executed end to end yet (no running Docker daemon or VPS in the build environment). `docker compose config` validates; scripts pass `bash -n`.

## Option A — Docker Compose (local / single host)

```bash
cp deployment/docker.env.example deployment/docker.env      # fill APP_KEY, DB_*, REVERB_*, GATEWAY_*
cp deployment/gateway.env.example deployment/gateway.env    # same GATEWAY_API_TOKEN/WEBHOOK_SECRET, new GATEWAY_STATE_KEY
docker compose -f deployment/docker-compose.yml --env-file deployment/docker.env up -d --build
docker compose -f deployment/docker-compose.yml exec app php artisan migrate --force
```

Only nginx is published (`HTTP_PORT`, default 8000). MySQL, Redis, Reverb and the gateway are reachable only on the compose network. Browsers reach Reverb via nginx `/app`; Laravel publishes to `reverb:8080` directly.

## Option B — VPS

### 1. Provision (as root)

```bash
apt install nginx mysql-server redis-server supervisor php8.4-fpm php8.4-{mysql,redis,intl,mbstring,xml,zip,bcmath,curl} composer sqlite3 age certbot python3-certbot-nginx
# Node 22+/24 LTS from NodeSource or distro packages
adduser --disabled-password deploy
mkdir -p /var/www/whatsapp-workspace/{releases,shared/storage,shared/gateway/data} /var/log/whatsapp-workspace
chown -R deploy:deploy /var/www/whatsapp-workspace /var/log/whatsapp-workspace
chmod 700 /var/www/whatsapp-workspace/shared/gateway/data
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable   # 3100, 8080, 3306, 6379 stay closed
```

MySQL and Redis listen on `127.0.0.1` only (Ubuntu defaults); create a dedicated MySQL user with privileges on `whatsapp_workspace` only, and set `requirepass` for Redis.

Allow `deploy` to reload PHP-FPM and restart the gateway: `/etc/sudoers.d/deploy` → `deploy ALL=NOPASSWD: /bin/systemctl reload php8.4-fpm, /usr/bin/supervisorctl restart whatsapp-workspace\:ww-gateway`.

### 2. Configuration

- `/var/www/whatsapp-workspace/shared/.env` from `.env.example` with `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, real DB/Redis credentials, `php artisan key:generate --show` → `APP_KEY`.
- **Reverb — bind vs public address:**
    - `REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080` — where the Reverb process listens (private).
    - `REVERB_HOST=workspace.example.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https` — what browsers (and Laravel's publisher, through nginx) use. `VITE_REVERB_*` mirror these at build time.
- `/var/www/whatsapp-workspace/shared/gateway/.env` (mode 0600) from `services/whatsapp-gateway/.env.example`: `GATEWAY_HOST=127.0.0.1`, `GATEWAY_DATA_DIR=/var/www/whatsapp-workspace/shared/gateway/data`, `GATEWAY_WEBHOOK_URL=https://workspace.example.com/api/gateway/events`, matching token/secret, `GATEWAY_STATE_KEY=$(openssl rand -base64 32)` — store this key in your password manager too.
- Leave `MESSAGING_UNOFFICIAL_ENABLED=false` unless you are running a controlled test.

### 3. Web server, processes, schedule

```bash
cp deployment/nginx/whatsapp-workspace.conf /etc/nginx/sites-available/ && ln -s ../sites-available/whatsapp-workspace.conf /etc/nginx/sites-enabled/
certbot --nginx -d workspace.example.com
cp deployment/supervisor/whatsapp-workspace.conf /etc/supervisor/conf.d/ && supervisorctl reread && supervisorctl update
cp deployment/logrotate/whatsapp-workspace /etc/logrotate.d/
crontab -u deploy deployment/scripts/cron
```

### 4. Deploy / rollback

```bash
sudo -u deploy REPO=git@github.com:you/whatsapp-workspace.git REF=main deployment/scripts/deploy.sh
sudo -u deploy deployment/scripts/rollback.sh
```

`deploy.sh` builds a new release directory (composer `--no-dev`, Vite build, gateway build), runs `migrate --force`, caches config/routes/views/events, switches the `current` symlink, reloads PHP-FPM, then `horizon:terminate` (Supervisor restarts on new code), `reverb:restart` (graceful) and restarts the gateway (SIGTERM: sockets closed without logout, sessions restored on start).

**Rollback** re-points `current` and restarts workers. It does not reverse migrations: write additive migrations, and ship a forward fix if a schema change must be undone.

### 5. Post-deploy verification

- `curl -fsS https://workspace.example.com/up` → 200
- Dashboard → _System health_ all green (Database, Redis, providers; gateway shows _Gateway ready_ when enabled)
- `curl -fsS http://127.0.0.1:3100/ready` on the server; from outside, port 3100 must be unreachable
- `/horizon` shows active supervisors; browser console shows no CSP violations; Reverb connects over `wss://`.

## Horizontal scaling (not supported yet)

One gateway process owns all sockets. Scaling out needs: a per-session ownership lease (e.g. Redis), a shared encrypted auth store, a shared outbox, and session-sticky routing from Laravel. Laravel web/Horizon can scale out today (Redis-backed cache locks, `onOneServer` scheduler).
