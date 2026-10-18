# WhatsApp Workspace — project rules

Read `docs/architecture.md`, `docs/decisions.md` and the checkpoint in `docs/implementation-plan.md` before changing behaviour.

## Architecture

- Laravel owns all business data and logic; `services/whatsapp-gateway` (Node, Baileys) owns only the WhatsApp Web protocol, QR, sockets and encrypted auth state. Never add business rules, campaigns or queues to the gateway.
- Talk to WhatsApp only through `App\Contracts\MessagingProvider` (resolved by `MessagingProviderRegistry`). Never call the gateway or Meta directly from controllers, jobs or actions.
- Gateway → Laravel traffic is a signed webhook persisted to `gateway_events` and processed by `ProcessGatewayEvent`. Keep processing idempotent; never trust event order.
- Device status changes only via `ApplyDeviceStatus` (state machine in `App\Enums\DeviceStatus`). Message status only moves forward (`MessageStatus::canAdvanceTo`).
- Outgoing messages: persist first (`QueueOutgoingMessage`), deliver in `SendOutgoingMessage`. An unknown outcome is `unknown`, never auto-resent; retries reuse the idempotency key.
- Controllers stay thin: Form Requests validate, actions/services do the work, resources shape output. Follow sibling files.
- Single workspace, no roles (POC, D-002). Keep routes behind `auth`; keep broadcast channels private.

## Security — non-negotiable

- Never expose, log, broadcast or return Baileys credentials, Signal keys, QR strings, tokens or secrets. Never put backend secrets in `VITE_*`.
- Do not log or audit message bodies. Notifications must not contain message content.
- Never implement anti-ban evasion, fingerprint spoofing, proxy rotation, contact scraping, sending-limit circumvention or bulk messaging over the unofficial provider.
- Never infer consent; imported or manual contacts start without consent.

## Workflow

- Add Pest tests (Laravel) or `node:test` tests (gateway) for every behaviour change; run the narrow tests, then `composer ci:check` and `cd services/whatsapp-gateway && npm run typecheck && npm test`.
- Run `vendor/bin/pint --dirty --format agent` and `npm run check:fix` before committing.
- Update `docs/gateway-api.md` / `docs/events.md` when an interface changes, and record notable choices in `docs/decisions.md`.
- Do not add dependencies without the owner's approval.

## Commands

- `composer dev` — app, Vite, Horizon, Reverb, logs, and the gateway (once `services/whatsapp-gateway/.env` exists).
- `php artisan migrate:fresh --seed` — demo data (`demo@example.com` / `password`, mock devices, fictional 555-01xx numbers).
- `php artisan test --compact` · `vendor/bin/phpstan analyse` · `npm run check` · `npm run types:check`.
- Gateway: `cd services/whatsapp-gateway && npm test && npm run typecheck && npm run build`.

## Framework guidelines

Laravel Boost generates detailed framework guidelines into `AGENTS.md` (run `php artisan boost:install` after cloning). Project rules above live in `.ai/guidelines/whatsapp-workspace.md` and are merged into it.

@AGENTS.md
