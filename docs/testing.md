# Testing

## Automated

| Suite              | Command                                                                            | Covers                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------ | ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Laravel (Pest)     | `php artisan test --compact`                                                       | auth (starter), devices & state machine, QR expiry, webhook signature/replay/size/schema, providers (HTTP outcome mapping), outbound (queue-before-send, accepted/rejected/unknown, retry with same key, interrupted sends), inbound (dedup, LID without phone, unsupported types, reopen), out-of-order receipts, attachments (allow-list, private download), contacts & consent (STOP opt-out, duplicates), templates (injection-safe rendering), automations (word boundaries, dry run, rate limit, loop prevention, auto-reply gating, business hours, tags), dashboard (accurate counts, no message content), channel authorization, broadcast payloads, security headers, reconciliation & retention |
| Static analysis    | `vendor/bin/phpstan analyse` (Larastan level 7)                                    | app, config, database, routes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| Formatting         | `vendor/bin/pint --test`, `npm run check` (Vite+ lint + format, type-aware)        | PHP, TS/TSX, docs                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Frontend types     | `npm run types:check`                                                              | strict TypeScript                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Gateway            | `cd services/whatsapp-gateway && npm run typecheck && npm test` (Node `node:test`) | AES-GCM store, creds/Signal-key persistence across restart, atomic key batches, outbox signing/ordering/backoff/dead-letter/restart, session lifecycle (QR → connected, duplicate socket prevention, restore without QR, bounded reconnect, logout deletes creds, revoke), idempotent sends, send timeout = unknown, HTTP auth/validation/errors/correlation ids, Baileys normalization (LID, groups, media, status mapping), disconnect classification, config validation                                                                                                                                                                                                                                 |
| Everything CI runs | `composer ci:check` (+ gateway job)                                                | `.github/workflows/tests.yml`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |

The suite never needs a WhatsApp account, MySQL or Redis (SQLite in memory, sync queue, null broadcaster).

**Not automated (no tooling installed / approved):** React component tests and browser E2E tests. Adding them requires new dev dependencies (e.g. `pestphp/pest-plugin-browser` + Playwright, or Vitest + Testing Library). The flows below are therefore manual.

## Live integration checks already performed (2026-10-18, local)

| Check                     | Setup                                              | Result                                                        |
| ------------------------- | -------------------------------------------------- | ------------------------------------------------------------- |
| Laravel ↔ gateway pairing | Laravel + Horizon + gateway `GATEWAY_ADAPTER=mock` | `connecting → qr_ready → connected`; QR absent from both logs |
| Restart recovery          | SIGTERM gateway, restart                           | `disconnected` then restored to `connected` without QR        |
| Revoke                    | Laravel revoke → gateway                           | creds/keys rows deleted; `logged_out`                         |
| Real-time                 | Reverb + two signed-in WebSocket clients + guest   | both users got `ConversationUpdated` in 0.2–0.9 s; guest 403  |
| Automations               | Horizon `automations` queue                        | seeded rule produced a suggested reply                        |

## Manual browser checklist (mock provider, no phone needed)

Run `composer dev` (serves app, Horizon, Reverb, Vite, logs, and the gateway if configured), then `php artisan migrate:fresh --seed`, log in as `demo@example.com` / `password`.

1. **Devices** → _Add device_ (Mock) → _Connect_: QR dialog shows a code and countdown → _Simulate scan_ → dialog closes, badge turns _Connected_ without reloading.
2. Wait 60 s on an unscanned QR → "This code expired" → _Generate new code_ works.
3. Device ⋯ → _Simulate incoming_ → open **Inbox** in a second browser window (another user, e.g. `agent@example.com`): the conversation appears/moves to top and unread badge increments live in both windows.
4. Open the conversation: unread clears; send "Hello" → clock → single check → double check. Send "x #fail" → red _Failed_ with _Retry_; "x #timeout" → amber _Not confirmed_.
5. Scroll up in a long thread → _Load earlier messages_; while scrolled up, a new incoming message shows the "1 new message" button instead of jumping.
6. Attach a PDF and a PNG; both render; links download only while signed in.
7. Stop Reverb → the inbox shows "Live updates are offline"; restart → banner disappears; no duplicate messages appear.
8. Contacts → create `+1 202 555 0142` → consent shows "No consent recorded"; record consent with evidence; send "STOP" via _Simulate incoming_ from that number → consent revoked.
9. Templates → create with `{{contact_name}}` → preview renders; in the inbox composer the template button inserts it with the real name.
10. Automations → new rule (keyword `price`, suggest reply, **dry run**) → simulate "what's the price" → log says _Dry run_; untick dry run → suggestion banner appears in the composer.
11. Dashboard → numbers match; "Includes simulated data" note visible; health lists Database, Redis and providers.
12. Dark mode (Settings → Appearance) on Inbox and Dashboard chart.
13. Production-mode CSP: `SECURITY_CSP_ENABLED=true npm run build && php artisan serve` → browser console shows no CSP violations on Inbox/Devices/Dashboard.

## Manual live WhatsApp checklist (dedicated, consenting test phone only)

⛔ **Not yet performed.** Baileys behaviour against real WhatsApp is unverified until this list is completed.

Preconditions: a phone number used only for testing; the account owner consents; nothing sensitive on it. Expect that WhatsApp may restrict the number.

1. `services/whatsapp-gateway/.env`: `GATEWAY_ADAPTER=baileys`, fresh `GATEWAY_STATE_KEY`, tokens matching Laravel. Laravel `.env`: `MESSAGING_UNOFFICIAL_ENABLED=true`.
2. Start: `composer dev` (or `npm --prefix services/whatsapp-gateway run dev` + `php artisan horizon` + `php artisan reverb:start`).
3. Devices → add (WhatsApp Web) → Connect → scan within ~20 s via _WhatsApp → Settings → Linked devices_. ✅ Badge shows _Connected_ **only after** the phone finishes linking; phone number shown.
4. From a second phone, send a text → appears in the inbox in ≤ 2 s; contact has the right number (or "not shared" for LID-only senders).
5. Reply from the inbox → arrives on the second phone; status advances sent → delivered → read (read requires read receipts on).
6. Send an image and a PDF both ways; incoming media downloads and renders.
7. Stop the gateway (Ctrl-C) → device _Disconnected_; start again → _Connected_ without a new QR.
8. Kill the network for 1 min → _Reconnecting_ with attempts → back to _Connected_.
9. On the phone, remove the linked device → app shows _Logged out_; gateway DB has no creds for that session.
10. Revoke from the app on a connected device → phone no longer lists the linked device.
11. Check `storage/logs/laravel.log` and gateway output: no QR strings, keys or message text.

Record date, Baileys version and results in `docs/implementation-plan.md` when done.
