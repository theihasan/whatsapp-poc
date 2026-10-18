# Security

## Controls in place (verified by tests unless noted)

| Area              | Control                                                                                                                                                                                                   | Where                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| Authentication    | Fortify sessions, CSRF, login throttling (5/min per email+IP), optional 2FA/passkeys                                                                                                                      | starter kit                                                                |
| Authorization     | Every app route requires `auth` + `verified`; private broadcast channels reject guests; per-conversation channel requires an existing conversation                                                        | `routes/web.php`, `routes/channels.php`, `tests/Feature/Broadcasting`      |
| Gateway → Laravel | HMAC-SHA256 over `{timestamp}.{rawBody}`, ±300 s window, unique `event_id` (replay/duplicate), 1 MB body limit, schema validation, secret rotation                                                        | `VerifyGatewaySignature`, `tests/Feature/Gateway/GatewayWebhookTest.php`   |
| Laravel → Gateway | Bearer token (constant-time compare), correlation ids, private bind `127.0.0.1`, timeouts                                                                                                                 | gateway `security/auth.ts`, `tests/http.test.ts`                           |
| Protocol secrets  | Baileys creds + Signal keys AES-256-GCM encrypted with `GATEWAY_STATE_KEY` (separate from `APP_KEY`), row-bound associated data, file mode 0600, dir 0700; never returned by any API, never sent to React | gateway `auth/`, `tests/auth-state.test.ts`                                |
| QR codes          | Cache-only with TTL, `Cache-Control: no-store`, never logged (pino redaction) or broadcast                                                                                                                | `DeviceQrStore`, `BroadcastPayloadTest`                                    |
| Webhook outbox    | Encrypted at rest (message text included)                                                                                                                                                                 | gateway `events/outbox.ts`                                                 |
| Attachments       | MIME allow-list (no SVG/HTML), size limit, private disk, authenticated download, `nosniff`, images inline only for raster types                                                                           | `SendMessageRequest`, `AttachmentController`, `DownloadIncomingAttachment` |
| Output            | React escapes all text; templates render in a single pass (no placeholder injection), control characters stripped                                                                                         | `TemplateRenderer`                                                         |
| Search            | LIKE wildcards escaped                                                                                                                                                                                    | `Contact::search`, tests                                                   |
| Headers           | `nosniff`, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy, COOP; HSTS on HTTPS in production; CSP with per-request script nonce (styles allow inline for UI libraries)                      | `SecurityHeaders`, `SecurityHeadersTest`                                   |
| Rate limits       | login, webhook (1200/min/IP), send message (60/min/user), device connect (10/min)                                                                                                                         | `AppServiceProvider`, routes                                               |
| Logs & audit      | Audit log stores actions, ids and non-sensitive properties only; notifications carry no message content; gateway errors return codes without stack traces                                                 | `AuditLogger`, `tests/http.test.ts`                                        |
| Config            | No backend secret in `VITE_*` (only the public Reverb app key); `APP_DEBUG=false` in deployment templates; `.dockerignore` keeps `.env` and gateway data out of images                                    | `.env.example`, `deployment/`                                              |
| Dependencies      | `composer audit` and `npm audit --omit=dev` (app + gateway) clean on 2026-10-18; CI runs them                                                                                                             | `.github/workflows/tests.yml`                                              |

## Known limitations (POC)

1. **No roles; open registration (D-002).** Any registered user can control every device and read every conversation. Do not expose a deployment publicly without disabling registration (`config/fortify.php` → remove `Features::registration()`) or adding an allow-list.
2. **Horizon dashboard** is available to any signed-in user (same reason).
3. **CSP allows inline styles.** Script execution is nonce-restricted; styles are not.
4. **CSP not browser-verified.** Header correctness is tested; the built app has not been exercised in a browser with CSP on (no browser automation in this environment). Check the console for violations after deploy.
5. **Dev-only advisory:** starter-kit `vite-plus@0.3.0` depends on a `tinypool` version with a critical advisory (prototype pollution, GHSA-85c8-ppgw-ccpr). It is a build tool, not shipped to browsers; the fix requires `vite-plus@0.3.3` (dependency change pending owner approval).
6. **Phone validation** is E.164 shape only (D-016).

## Compliance

- **Baileys is unofficial** and not endorsed by WhatsApp. WhatsApp's terms restrict unauthorized clients; linked numbers can be restricted or banned. The unofficial provider is disabled by default (`MESSAGING_UNOFFICIAL_ENABLED=false`) and must only be used with dedicated, consenting test accounts. Technical readiness ≠ authorization to operate.
- No anti-ban evasion, fingerprint manipulation, proxy rotation, contact discovery or rate-limit circumvention is implemented — and must not be added (see `CLAUDE.md`).
- Bulk/promotional messaging is not implemented (campaigns deferred, D-003). The provider capability `supportsBulkMessaging` is `false` for the unofficial provider.
- Consent is never inferred: manually created contacts have no consent; `STOP`-style replies revoke consent automatically (D-017).
- Local templates are **not** Meta-approved WhatsApp message templates.
- The official Cloud API adapter uses documented endpoints only and has **not** been tested against a live Meta account.

## Secrets handling

| Secret                                   | Lives in                        | Notes                                                                 |
| ---------------------------------------- | ------------------------------- | --------------------------------------------------------------------- |
| `APP_KEY`                                | Laravel `.env`                  | sessions/encryption                                                   |
| `GATEWAY_API_TOKEN`                      | both `.env` files               | rotate together                                                       |
| `GATEWAY_WEBHOOK_SECRET` (+ `_PREVIOUS`) | both `.env` files               | zero-downtime rotation, see `docs/gateway-api.md`                     |
| `GATEWAY_STATE_KEY`                      | gateway `.env` only             | **back up separately** from backups; losing it = re-link every device |
| `REVERB_APP_SECRET`                      | Laravel `.env`                  | `REVERB_APP_KEY` is public by design                                  |
| `WHATSAPP_CLOUD_ACCESS_TOKEN`            | Laravel `.env`                  | system-user token, least privilege                                    |
| DB/Redis passwords                       | `.env`, `~/.my.cnf` for backups | never on command lines                                                |
