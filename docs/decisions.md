# Architectural Decisions

Each entry: context → decision → consequence. Newest last.

## D-001 — Build on the existing React starter kit

The repository already contains the official Laravel React starter kit (Laravel 13.37, Inertia 3.5, React 19, Fortify, Wayfinder, Pest 5, Larastan 3, Pint). We configure it instead of re-scaffolding. PHP runtime is 8.4.16 (spec: 8.4); CI keeps the starter's 8.5 pin plus an 8.4 matrix entry.

## D-002 — Single workspace, no roles (POC scope)

The owner confirmed this is a proof of concept: one implicit workspace, every authenticated user can do everything, Fortify open registration stays on. There is no `workspaces` / `workspace_user` table. Authorization still runs through policies and channel callbacks (all "authenticated user" today) so roles can be added later without touching controllers.
**Known limitation:** anyone who can reach the site can register and control linked devices. Do not expose a POC deployment publicly without adding registration gating.

## D-003 — Campaigns deferred

Module H is out of scope for this build (owner decision). Templates (Module G) remain as local reusable replies. Provider capability flags (`supportsBulkMessaging`) are kept on the provider contract so campaigns can be added later behind an official-provider check.

## D-004 — Provider is chosen per WhatsApp account

`whatsapp_accounts.provider` (`mock` | `unofficial_web` | `official_cloud`). New accounts default to `config('messaging.default_provider')` (`mock`). `unofficial_web` is only selectable when `MESSAGING_UNOFFICIAL_ENABLED=true`; it defaults to `false`, and production must set it explicitly.

## D-005 — Mock provider runs in-process, without Node

`MockMessagingProvider` emits the same normalized gateway events (via `ProcessGatewayEvent`) the real gateway would, so the whole Laravel pipeline (event store → jobs → broadcasts → UI) is exercised deterministically with no sidecar. The gateway separately has a `mock` adapter so the Node HTTP/webhook path can be tested end to end without WhatsApp.

## D-006 — Baileys pinned to 7.0.0-rc14

npm `latest` for `@whiskeysockets/baileys` is `7.0.0-rc14`; the 6.x line is tagged `legacy` (6.7.24). Both were published 2026-07-29. We pin `7.0.0-rc14` exactly (ESM, Node ≥ 20) because upstream treats 6.x as legacy. Risk: release candidate — protocol behavior may change. Baileys is isolated behind the gateway's `WhatsAppAdapter` interface.

## D-007 — Gateway auth state in encrypted SQLite (`node:sqlite`)

Baileys credentials and Signal keys are stored in a gateway-local SQLite file using Node's built-in `node:sqlite` (no native addon). Every value is AES-256-GCM encrypted with `GATEWAY_STATE_KEY` (32 bytes, separate from all Laravel secrets). Key writes run in one transaction and the `set()` promise resolves only after commit. `useMultiFileAuthState` is not used. `node:sqlite` is marked experimental in Node 22; acceptable for a POC, revisit for production.

## D-008 — No `outbound_message_requests` table

The outbound idempotency key lives on `messages.idempotency_key` (unique). The gateway deduplicates sends by that key. A separate request table would duplicate state for no POC benefit.

## D-009 — No `workspaces`, `conversation_assignments` tables

Assignment is `conversations.assigned_user_id`; history of assignment changes is recorded in `audit_logs`. Contact notes are a text column on `contacts`.

## D-010 — Tests run on in-memory SQLite

Keeps the starter kit's `phpunit.xml` default; CI needs no MySQL. Migrations stay portable (no MySQL-only column types). Local development uses MySQL (server reports version 26.7.0 rather than the spec's 8.x; only standard SQL is used).

## D-011 — Temporary QR codes live in cache only

The QR string is stored in Redis with a 60-second TTL (`device-qr:{accountId}`), never in MySQL, never logged and never broadcast. The devices channel only announces `qr_ready`; the open QR modal then fetches the string from an authenticated route (`GET /devices/{account}/qr`). The browser renders it with `qrcode.react`.

## D-012 — Webhook signature scheme

`X-Gateway-Signature: sha256=HMAC_SHA256(secret, "{timestamp}.{rawBody}")`, `X-Gateway-Timestamp` (unix seconds, ±300 s tolerance), `X-Gateway-Event-Id`. Laravel accepts the current secret and an optional `GATEWAY_WEBHOOK_SECRET_PREVIOUS` for rotation. Replays inside the window are rejected by the unique `gateway_events.event_id`, and duplicates return `200 {"status":"duplicate"}` so the gateway stops retrying.

## D-013 — Real-time channels without a workspace segment

Because of D-002, channels are `private-devices`, `private-conversations`, `private-conversations.{conversationId}`. Authorization callbacks require an authenticated user and (for the per-conversation channel) an existing conversation.

## D-014 — Broadcast immediately after commit, rescued

Live testing showed ~3 s UI latency: each broadcast was a second queued job and idle workers sleep 3 s between polls. Broadcast events now implement `ShouldBroadcastNow` + `ShouldDispatchAfterCommit` + `ShouldRescue`: they are sent right after the transaction commits, in whichever process made the change (usually a Horizon job), and a Reverb outage is reported instead of failing the request/job. Clients still converge on reload because server props are the source of truth.

## D-015 — Node built-in test runner for the gateway

`npm install vitest` crashes on npm 10.9 (`Cannot read properties of null (reading 'edgesOut')` while resolving optional peers). The gateway uses `node --test` with native TypeScript type stripping instead — no test dependency, same assertions.
