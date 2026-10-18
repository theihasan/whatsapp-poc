# Deployment Checklists

Step-by-step companion to `docs/deployment.md` (procedure) and `docs/operations.md` (troubleshooting). Copy into the release ticket and tick as you go. Target: one Ubuntu 24.04 VPS.

---

## 1. Pre-deployment checklist

### 1.1 Go / no-go decisions (owner)

- [ ] **Registration gated.** Open registration + no roles means any sign-up controls every device (D-002). Disable `Features::registration()` in `config/fortify.php` or restrict access (VPN / IP allow-list / basic auth) before the site is public.
- [ ] **Provider decided.** `MESSAGING_UNOFFICIAL_ENABLED=false` unless this is a controlled test with dedicated, consenting numbers. Production messaging = official Cloud API with your own Meta authorization (adapter untested live).
- [ ] **Open dependency items** reviewed: `vite-plus` dev advisory (D-020), phone library (D-016).
- [ ] Release commit/tag chosen; `CHANGELOG`/release notes written; maintenance window communicated.

### 1.2 Code quality gates (on the release commit)

- [ ] GitHub Actions green: `ci` (PHP 8.4 + 8.5), `gateway`, `audit`.
- [ ] Locally or in CI: `composer ci:check` → lint, format, types, Pint, PHPStan, Pest all pass.
- [ ] `cd services/whatsapp-gateway && npm run typecheck && npm test && npm run build` pass.
- [ ] `composer audit` and `npm audit --omit=dev` (root + gateway) clean.
- [ ] New migrations are **additive / backward compatible** (rollback does not undo migrations).
- [ ] `docs/gateway-api.md` / `docs/events.md` updated if interfaces changed.

### 1.3 Server provisioning (first deploy only)

- [ ] Ubuntu 24.04 patched; time sync on (`timedatectl` → NTP active — webhook signatures reject > 300 s skew).
- [ ] Packages: nginx, php8.4-fpm (+ mysql, redis, intl, mbstring, xml, zip, bcmath, curl), mysql-server 8.4, redis-server, supervisor, composer, sqlite3, age, certbot, Node.js ≥ 22.18 (24 LTS recommended).
- [ ] `deploy` user created; no password login; SSH keys only; root SSH disabled.
- [ ] Directories: `/var/www/whatsapp-workspace/{releases,shared/storage,shared/gateway/data}`, `/var/log/whatsapp-workspace`, owned by `deploy`; `shared/gateway/data` mode **0700**.
- [ ] Firewall (`ufw`): only 22, 80, 443 open. **3100 (gateway), 8080 (Reverb), 3306, 6379 closed.**
- [ ] MySQL: dedicated user limited to `whatsapp_workspace`; bound to 127.0.0.1; `~deploy/.my.cnf` (0600) for backups.
- [ ] Redis: bound to 127.0.0.1, `requirepass` set, `appendonly yes`.
- [ ] `/etc/sudoers.d/deploy` allows only `systemctl reload php8.4-fpm` and `supervisorctl restart whatsapp-workspace:ww-gateway`.
- [ ] DNS A/AAAA record for the domain points to the server.

### 1.4 Secrets & configuration

- [ ] `shared/.env` created from `.env.example`:
    - [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `APP_KEY` generated (`php artisan key:generate --show`)
    - [ ] `DB_*`, `REDIS_PASSWORD` set; `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb`
    - [ ] Reverb **bind**: `REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080`
    - [ ] Reverb **public**: `REVERB_HOST=<domain>`, `REVERB_PORT=443`, `REVERB_SCHEME=https`; `VITE_REVERB_*` mirror them
    - [ ] `REVERB_APP_ID/KEY/SECRET` generated (fresh, not copied from local)
    - [ ] `GATEWAY_URL=http://127.0.0.1:3100`, `GATEWAY_API_TOKEN` and `GATEWAY_WEBHOOK_SECRET` = `openssl rand -hex 32` each
    - [ ] `MESSAGING_DEFAULT_PROVIDER` and `MESSAGING_UNOFFICIAL_ENABLED` per 1.1
    - [ ] Mail settings configured (password reset emails)
- [ ] `shared/gateway/.env` (mode **0600**) from `services/whatsapp-gateway/.env.example`:
    - [ ] `NODE_ENV=production`, `GATEWAY_HOST=127.0.0.1`, `GATEWAY_ADAPTER=baileys`
    - [ ] `GATEWAY_DATA_DIR=/var/www/whatsapp-workspace/shared/gateway/data`
    - [ ] `GATEWAY_WEBHOOK_URL=https://<domain>/api/gateway/events`
    - [ ] Token and webhook secret **identical** to Laravel's
    - [ ] `GATEWAY_STATE_KEY=$(openssl rand -base64 32)` — **also stored in the password manager/KMS**, never next to backups
- [ ] No secret in `VITE_*` except the public `REVERB_APP_KEY`.
- [ ] `.env` files are not in git; `git status` on the server is clean.

### 1.5 Web server, processes, schedule (first deploy or when changed)

- [ ] `deployment/nginx/whatsapp-workspace.conf` installed with the real domain; `nginx -t` passes.
- [ ] TLS issued: `certbot --nginx -d <domain>`; auto-renew timer active (`systemctl list-timers | grep certbot`).
- [ ] `deployment/supervisor/whatsapp-workspace.conf` installed; `supervisorctl reread && supervisorctl update`.
- [ ] `deployment/logrotate/whatsapp-workspace` in `/etc/logrotate.d/`.
- [ ] Cron installed for `deploy`: `crontab -u deploy deployment/scripts/cron` (scheduler, backup, disk check).
- [ ] Backup env set for cron: `BACKUP_AGE_RECIPIENT` (public key; private key kept offline), optional `BACKUP_GATEWAY_STATE=1`, `ALERT_WEBHOOK_URL`.

### 1.6 Immediately before running the deploy

- [ ] Fresh backup taken and confirmed (`deployment/scripts/backup.sh`, file exists and is non-empty).
- [ ] Note the current release directory (rollback target): `readlink /var/www/whatsapp-workspace/current`.
- [ ] Horizon queue backlog low (`/horizon`), no long-running jobs you need to wait for.
- [ ] Disk space > 20 % free (`df -h /var/www`).

---

## 2. Deployment

- [ ] Run: `sudo -u deploy REPO=<git url> REF=<tag> deployment/scripts/deploy.sh`
- [ ] Script completed without errors (composer, Vite build, gateway build, `migrate --force`, caches, symlink switch, PHP-FPM reload, `horizon:terminate`, `reverb:restart`, gateway restart).
- [ ] First deploy only: create the first user (registration page or `php artisan tinker`), then gate registration (1.1).
- [ ] Do **not** run `db:seed` in production (demo data).

---

## 3. Post-deployment checklist

### 3.1 Smoke tests (within 5 minutes)

- [ ] `curl -fsS https://<domain>/up` → 200.
- [ ] `readlink /var/www/whatsapp-workspace/current` points to the new release.
- [ ] Login works; Dashboard loads; **System health** shows Database ✅, Redis ✅ and each enabled provider ✅ (_Gateway ready_ when unofficial is enabled).
- [ ] `sudo supervisorctl status` → `ww-horizon`, `ww-reverb`, `ww-gateway` all `RUNNING` with fresh uptimes.
- [ ] `/horizon` shows supervisors active and queues `events`, `messages`, `automations`, `default` processing.
- [ ] `php artisan schedule:list` shows reconcile (5 min), prune (03:15), horizon snapshot, prune-failed.
- [ ] `storage/logs/laravel.log` and `/var/log/whatsapp-workspace/*.log` have no new errors.

### 3.2 Security checks

- [ ] From **outside** the server: ports 3100, 8080, 3306, 6379 are unreachable (`nc -vz <ip> 3100` etc. fail).
- [ ] On the server: `curl -fsS http://127.0.0.1:3100/ready` → `"status":"ready"`; without a token `curl -s -X POST http://127.0.0.1:3100/v1/sessions` → 401.
- [ ] `curl -sI https://<domain>/login` shows `Strict-Transport-Security`, `Content-Security-Policy` (with `nonce-`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`.
- [ ] HTTP → HTTPS redirect works; SSL Labs grade A or better.
- [ ] `APP_DEBUG` is off: a non-existent URL returns a plain 404 page, no stack trace.
- [ ] Unsigned webhook is refused: `curl -s -X POST https://<domain>/api/gateway/events -H 'Content-Type: application/json' -d '{}'` → 401.
- [ ] Browser devtools console on Inbox, Devices and Dashboard: **no CSP violations**.
- [ ] Registration gated as decided in 1.1.
- [ ] `shared/gateway/data` is 0700 and `gateway.sqlite` 0600; `shared/gateway/.env` is 0600.
- [ ] Grep logs for leaks — none of these should match: `grep -rE "MOCK-QR|wa\.me/settings|noiseKey|signedPreKey|advSecretKey|Bearer [A-Za-z0-9]{20}" /var/log/whatsapp-workspace storage/logs`.

### 3.3 Real-time

- [ ] Browser devtools → Network → WS: connection to `wss://<domain>/app/...` is open (101).
- [ ] Inbox shows **no** "Live updates are offline" banner.
- [ ] Two browsers signed in as different users: an incoming message updates both inbox lists without a refresh.

### 3.4 Messaging pipeline

Mock provider (always safe):

- [ ] Create a temporary **Mock** device → Connect → Simulate scan → _Connected_ live → Simulate incoming → appears in the Inbox → reply → reaches _Delivered_ → delete the temporary device.

Unofficial provider (only if enabled, with the dedicated test number):

- [ ] Existing devices restored after the gateway restart **without a new QR** (Devices → _Connected_).
- [ ] Send a test message to a consenting number → _Delivered_.
- [ ] Reply from that phone → appears in the Inbox within ~2 s.
- [ ] Send/receive an image → renders; download works only while signed in.

Official Cloud API (only if configured):

- [ ] Device _Connect_ shows _Connected_ with the verified business number; one text to a consenting test number is delivered.

### 3.5 Automations, notifications, scheduler

- [ ] Automations → Execution log shows entries for new incoming messages (if rules exist); nothing unexpected under _Failed_.
- [ ] Dashboard → Notifications receives new-conversation / failed-message items per workspace settings.
- [ ] After 5 minutes: `php artisan app:reconcile-messaging` output in the scheduler log shows a clean run; Horizon _Metrics_ shows a snapshot.

### 3.6 Backups & monitoring (first deploy, then after infra changes)

- [ ] Next morning: a new `backup-*.tar.gz.age` exists and is non-zero.
- [ ] **Restore drill** done on a scratch database (see `docs/operations.md` → Backup & restore); result recorded.
- [ ] `GATEWAY_STATE_KEY` confirmed retrievable from the password manager by a second person.
- [ ] Uptime check on `https://<domain>/up` configured (external monitor).
- [ ] Disk alert tested (`THRESHOLD=1 deployment/scripts/disk-check.sh` → alert received).
- [ ] Log rotation works (`logrotate -d /etc/logrotate.d/whatsapp-workspace` without errors).

### 3.7 Close-out

- [ ] Old releases pruned to five (automatic) and enough disk left.
- [ ] Release recorded (tag, time, operator) and results of this checklist attached.
- [ ] `docs/implementation-plan.md` updated if anything was verified for the first time (e.g. live checklist items).
- [ ] Monitor `/horizon` failed jobs and the Dashboard failure tile for the next 24 h.

---

## 4. Rollback triggers

Roll back with `sudo -u deploy deployment/scripts/rollback.sh` if any of these occur and cannot be fixed within 15 minutes:

- [ ] `/up` not 200, or login/inbox returns 500s.
- [ ] Horizon cannot start, or `events`/`messages` queues stop draining.
- [ ] Webhooks rejected (gateway logs `webhook delivery failed` with 401/5xx) — check secrets and clock skew first.
- [ ] Messages duplicated, or statuses moving backwards.
- [ ] Devices that were connected do not restore after the gateway restart (check `GATEWAY_STATE_KEY` and data dir first — rolling back does not fix a wrong key).

After rollback: migrations are **not** reverted — if the release added a breaking schema change, ship a forward-fix migration. Re-run sections 3.1–3.4 against the restored release.
