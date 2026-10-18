# WhatsApp Workspace (POC)

A self-hosted WhatsApp conversation workspace: shared live inbox, devices with QR linking, contacts with consent tracking, reusable templates, rule-based automation and a dashboard.

Built as a **Laravel 13 modular monolith** (React 19 + Inertia 3 + Tailwind 4 + shadcn/ui, MySQL, Redis, Horizon, Reverb) with a small **Node.js gateway** (`services/whatsapp-gateway`: Fastify, Baileys, encrypted SQLite auth state) for the WhatsApp Web protocol.

> ⚠️ **Compliance.** The WhatsApp Web provider uses [Baileys](https://github.com/WhiskeySockets/Baileys), which is **unofficial and not endorsed by WhatsApp**. Use it only with dedicated, consenting test accounts; numbers can be restricted. It is disabled by default. Production messaging requires the official WhatsApp Business Cloud API and your own Meta authorization. This project is not "WhatsApp-compliant" or production-authorized by virtue of running.

## Quick start (mock provider — no phone needed)

Requirements: PHP 8.4+, Composer, Node 22.18+, MySQL 8+, Redis.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_* in .env, then:
php artisan migrate --seed
php artisan install:broadcasting --reverb   # once, if REVERB_* keys are empty
composer dev                                 # app :8000, Vite, Horizon, Reverb, logs
```

Log in as `demo@example.com` / `password`. Devices → _Connect_ → _Simulate scan_; then _Simulate incoming_ to feed the inbox.

### Adding the WhatsApp Web gateway

```bash
cd services/whatsapp-gateway && npm install && npm run build
cp .env.example .env    # set GATEWAY_API_TOKEN / GATEWAY_WEBHOOK_SECRET (same values in Laravel .env),
                        # GATEWAY_STATE_KEY=$(openssl rand -base64 32), GATEWAY_ADAPTER=baileys|mock
```

In Laravel `.env`: `MESSAGING_UNOFFICIAL_ENABLED=true`, `GATEWAY_URL=http://127.0.0.1:3100`, matching token/secret. `composer dev` now also starts the gateway. Use `GATEWAY_ADAPTER=mock` (optionally `GATEWAY_MOCK_AUTO_CONNECT_MS=3000`) to exercise the full Laravel ↔ gateway path without a phone.

## What's in it

| Module                                                                                                                          | Status                               |
| ------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ |
| Auth, profile, password, 2FA (starter kit)                                                                                      | ✅                                   |
| Devices: QR pairing, live status, history, reconnect, disconnect, revoke, mock simulation                                       | ✅ (live Baileys ⛔ unverified)      |
| Gateway: Fastify API, encrypted creds/Signal keys, ordered signed webhook outbox, bounded reconnect, restore, graceful shutdown | ✅                                   |
| Live inbox: filters, search, unread, assignment, status, attachments, emoji, retry, cursor history, real-time                   | ✅                                   |
| Contacts: CRUD, tags, notes, consent records, STOP opt-out                                                                      | ✅ (CSV import out of scope)         |
| Templates with safe placeholders                                                                                                | ✅ (local texts, not Meta templates) |
| Automations: keyword / hours / tag → assign / tag / notify / suggest / auto-reply, dry-run, rate limits, loop prevention        | ✅                                   |
| Dashboard & reporting, audit log, workspace settings                                                                            | ✅                                   |
| Official Cloud API adapter (text)                                                                                               | ⚠️ built from docs, untested live    |
| Campaigns                                                                                                                       | ⏸ deferred                           |

## Documentation

`docs/architecture.md` · `docs/decisions.md` · `docs/implementation-plan.md` · `docs/database.md` · `docs/gateway-api.md` · `docs/events.md` · `docs/security.md` · `docs/testing.md` · `docs/deployment.md` · `docs/operations.md` · `CLAUDE.md`

## Checks

```bash
composer ci:check                                   # lint, format, types, Pint, PHPStan, Pest
cd services/whatsapp-gateway && npm run typecheck && npm test
```
