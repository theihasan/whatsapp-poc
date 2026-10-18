# Implementation Plan

Status legend: ✅ done & verified · 🟡 in progress · ⬜ not started · ⛔ blocked / unverified

Scope changes vs. the master prompt are recorded in `docs/decisions.md` (single workspace, no roles, no campaigns, no CSV import).

## Checkpoint

> **Current phase:** 7 — Templates and automation
> **Next step:** template CRUD + rendering, rules engine with RunAutomations job.

## Phase 0 — Architecture and setup ✅

- MySQL `whatsapp_workspace`, Redis for cache/session/queue, Horizon, Reverb, Echo installed.
- `docs/architecture.md`, `docs/decisions.md`, this plan.
- **Verify:** `php artisan test --compact` (starter suite green), `php artisan migrate:status`.

## Phase 1 — Laravel foundation 🟡

- Enums, migrations, models, factories, seeders for: whatsapp_accounts, device_connection_events, contacts, tags, contact_tag, contact_consents, conversations, messages, message_attachments, gateway_events, message_templates, automation_rules, automation_executions, audit_logs.
- `config/messaging.php`, `MessagingProvider` contract, provider resolver, `MockMessagingProvider`.
- Navigation for all modules; `AuditLogger`.
- **Accept:** login works; guests redirected from every module route; seed data contains no real phone numbers.
- **Verify:** `php artisan test --compact`, `vendor/bin/phpstan analyse`, `npm run types:check`.
- Done: schema, enums, models, factories, provider layer, audit logger, Devices nav. Pending: seeders, nav entries for later modules (added per phase).

## Phase 2 — Gateway foundation ✅

- `services/whatsapp-gateway`: Fastify, Zod env + request validation, Pino (QR/keys redacted), bearer auth, correlation ids, `/health`, `/ready`, session routes.
- `WhatsAppAdapter` interface with `BaileysAdapter` and `MockAdapter`; `SessionManager` (one socket owner per session, backoff + jitter, bounded attempts); encrypted SQLite auth store; persistent webhook outbox with retries; graceful shutdown.
- **Accept:** gateway starts alone; unauthenticated/invalid requests rejected; auth-state round-trip and Signal-key persistence tests; webhook signing tests.
- **Verify:** `cd services/whatsapp-gateway && npm test && npm run typecheck && npm run build`.
- Result: 48/48 `node:test` tests pass. Vitest was dropped: npm 10.9 crashes resolving its optional peers (`edgesOut`); Node's built-in runner is allowed by the spec and needs no dependency.

## Phase 3 — QR and device connection ✅ (mock + gateway-mock verified; live Baileys ⛔ unverified)

- Devices CRUD, connect / reconnect / disconnect / revoke actions, `ProcessGatewayEvent` for `session.*`, state machine, connection history, duplicate-connection lock, QR modal with expiry.
- `UnofficialWebProvider` HTTP client.
- **Accept:** mock device reaches `qr_ready` then `connected` only on event; QR expires; revoke deletes gateway auth state; webhook signature/replay tests.
- Verified live across processes (Laravel + Horizon + gateway with `GATEWAY_ADAPTER=mock`): connect → `qr_ready` → `connected`; SIGTERM → `disconnected`; restart restores without QR → `connected`; revoke deletes gateway creds/keys. QR string absent from both logs.
- Found & fixed during the live run: empty-body `DELETE`/`POST` rejected by Fastify (400) — client now always sends `{}`.
- ⛔ Live Baileys verification is manual (`docs/testing.md` checklist) and stays unverified until run with the owner's test phone.

## Phase 4 — Core messaging ✅

- Inbox (3-panel), send endpoint, `SendOutgoingMessage` job, `message.received` / `message.status.updated` / `message.failed` processing, dedup, forward-only status, unknown-outcome handling, cursor pagination.
- Verified by `tests/Feature/Messaging/*`, `tests/Feature/Conversations/InboxTest.php` (118 Laravel tests green). Conversation list is capped at 100 most recent (no list pagination yet).

## Phase 5 — Real-time ✅ (server side verified live; browser rendering ⛔ unverified)

- Broadcast events after commit, channel authorization, `useEcho` subscriptions with cleanup, device status live updates, unread counters.
- Tests: `tests/Feature/Broadcasting/*` (guest 403 on every private channel, per-conversation channel requires an existing conversation, QR never in device broadcast, no raw gateway fields in message broadcast).
- Live (Reverb + Horizon + two signed-in WebSocket clients + one guest, scripted with pusher-js): both users received `ConversationUpdated` 0.2–0.9 s after a simulated incoming message; guest subscription rejected with 403.
- Found & fixed live: ~3 s latency (queued broadcast + 3 s worker sleep) → `ShouldBroadcastNow` + `ShouldRescue` after commit (D-014) and Horizon `sleep => 1`.
- ⛔ Not verified in a real browser (no browser automation available in this environment): React re-render on events, `useEcho` cleanup, offline banner. See `docs/testing.md` manual checklist.

## Phase 6 — Contacts and inbox improvements ✅

- Contact CRUD, tags, notes, search, consent records (grant / revoke), assignment, filters (All / Unread / Open / Closed / Mine), attachments (image, document) with private storage + authorized download, mark read.
- Added: STOP opt-out (D-017), E.164 normalization + duplicate detection (D-016), incoming media download from the gateway with MIME allow-list, inline notes in the inbox.
- Out of scope by owner decision: CSV import. Audio is accepted inbound and stored; there is no audio recorder for outbound.

## Phase 7 — Templates and automation ⬜

- Template CRUD with `{{placeholders}}`, validation, preview, insert into composer.
- Rules engine: incoming-message trigger; keyword / business-hours / tag conditions; assign / tag / notify / suggest-reply / auto-reply actions; priority, enable, dry-run, rate limit, loop prevention, execution log.

## Phase 8 — Dashboard and reporting ⬜

- Cached aggregates, activity chart, workload, provider/gateway health, mock-data label.

## Phase 9 — Hardening and deployment ⬜

- Activity/audit page, settings page, security headers, rate limits, static analysis, dependency audit, Docker Compose, Nginx/Supervisor configs, GitHub Actions, docs (`security`, `testing`, `deployment`, `operations`, `gateway-api`, `events`, `database`), README, CLAUDE.md.
