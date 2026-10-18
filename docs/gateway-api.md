# Gateway Internal API (v1)

Application-owned HTTP contract between Laravel and `services/whatsapp-gateway`. These are **not** Baileys APIs. The gateway listens on a private interface (`GATEWAY_HOST=127.0.0.1` by default) and must never be exposed publicly.

## Conventions

|             |                                                                                                                                 |
| ----------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Auth        | `Authorization: Bearer <GATEWAY_API_TOKEN>` on every route except `/health`, `/ready` → `401 {"error":{"code":"unauthorized"}}` |
| Correlation | `X-Correlation-Id` (8–128 chars `[\w.:-]`) is echoed back; generated when missing/invalid. Laravel sends one per request.       |
| Body        | JSON, max `GATEWAY_BODY_LIMIT_BYTES` (24 MB default, for base64 media)                                                          |
| Errors      | `{"error":{"code":"…","message":"…"}}`; validation adds `issues: [{path, message}]`. No stack traces.                           |
| Session id  | Laravel `whatsapp_accounts.id` (ULID), `^[0-9A-Za-z_-]{8,64}$`                                                                  |

## Routes

### `GET /health` → `200 {"status":"ok"}`

Liveness only.

### `GET /ready` → `200|503`

```json
{
    "status": "ready",
    "adapter": "baileys",
    "checks": { "store": true },
    "sessions": { "total": 2, "connected": 1 },
    "outbox": { "pending": 0, "dead": 0 }
}
```

Counts only — no identifiers.

### `POST /v1/sessions` `{"sessionId":"01J…"}` → `202 {"session": SessionView}`

Starts or resumes a session. **Idempotent:** if the session is already `connecting`/`qr_ready`/`connected`/`reconnecting`, returns the current view without opening a second socket.

### `GET /v1/sessions/:id` → `200 {"session": SessionView}` · `404 session_not_found`

```json
{
    "id": "01J…",
    "status": "connected",
    "phoneNumber": "+1…",
    "jid": "…@s.whatsapp.net",
    "displayName": "…",
    "lastError": null,
    "reconnectAttempts": 0,
    "hasCredentials": true
}
```

Never returns credentials or keys.

### `GET /v1/sessions/:id/qr` → `200 {"qr":"…","expiresAt":"ISO-8601"}` · `404 no_qr`

`Cache-Control: no-store`. Laravel normally receives QR codes through `session.qr.updated` instead.

### `POST /v1/sessions/:id/reconnect` → `202 {"session": SessionView}`

Closes any current socket and opens a fresh one; resets the attempt counter.

### `POST /v1/sessions/:id/disconnect` → `202 {"session": SessionView}`

Closes the socket, keeps credentials (`desired_state = stopped`, not restored on restart).

### `DELETE /v1/sessions/:id` → `202 {"revoked":true}`

Logs the device out on WhatsApp when connected, then deletes credentials, Signal keys and the send ledger for the session. Always emits `session.logged_out`.

### `POST /v1/sessions/:id/messages`

```json
{"idempotencyKey":"01J…","to":"15550100101@s.whatsapp.net","type":"text","text":"Hello"}
{"idempotencyKey":"01J…","to":"…","type":"image|document|audio","text":"optional caption","media":{"fileName":"a.pdf","mimeType":"application/pdf","base64":"…"}}
```

| Response                                                    | Meaning                            | Laravel action                          |
| ----------------------------------------------------------- | ---------------------------------- | --------------------------------------- |
| `200 {"messageId","status":"accepted","duplicate":false}`   | Handed to WhatsApp                 | `gateway_accepted`                      |
| `200 … "duplicate":true`                                    | Key already sent; same `messageId` | `gateway_accepted` (no resend happened) |
| `409 session_not_connected`                                 | Not sent                           | retry later (job backoff)               |
| `422 validation_failed` / `send_rejected` / `invalid_media` | Not sent                           | `failed`                                |
| `504 send_timeout`                                          | **May or may not have been sent**  | `unknown`; never auto-resent            |
| `5xx` other                                                 | Unknown                            | `unknown`                               |

`to` must be a user JID (`…@s.whatsapp.net` or `…@lid`). Groups/broadcasts are rejected.

### `GET /v1/sessions/:id/contacts/:jid/avatar` → `200` image bytes · `404 no_avatar` · `409 session_not_connected`

Preview-size WhatsApp profile picture. Only HTTPS `*.whatsapp.net` URLs are fetched; only JPEG/PNG ≤ 1 MB is returned. `404` when the contact has no picture or hides it from this account (privacy settings).

### `POST /v1/sessions/:id/contacts/resolve` `{"lids":["…@lid", …]}` (1–500) → `200 {"identities":[{lid, jid, phoneNumber}]}`

Phone-number identities for LIDs from the connected session's **local** mapping store (no lookup on WhatsApp). Unknown LIDs are omitted. `409 session_not_connected` when the session is not connected. Used by `php artisan app:resolve-contact-numbers`.

### `GET /v1/media/:mediaId` → binary (`Content-Type` = media type, `X-Media-File-Name` URL-encoded)

### `DELETE /v1/media/:mediaId` → `204`

Incoming media is held encrypted for at most 24 h; Laravel downloads it and then deletes it.

## Webhooks (gateway → Laravel)

`POST {GATEWAY_WEBHOOK_URL}` (Laravel: `POST /api/gateway/events`)

| Header                | Value                                                                          |
| --------------------- | ------------------------------------------------------------------------------ |
| `X-Gateway-Event-Id`  | event UUID                                                                     |
| `X-Gateway-Timestamp` | unix seconds                                                                   |
| `X-Gateway-Signature` | `sha256=` + hex `HMAC_SHA256(GATEWAY_WEBHOOK_SECRET, "{timestamp}.{rawBody}")` |

Body: the event envelope (see `docs/events.md`), ≤ 1 MB.

| Laravel response                            | Gateway behaviour                                                                               |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `202 accepted` / `200 duplicate`            | delete from outbox                                                                              |
| `401`, `413`, `422`, other 4xx              | permanent → dead-letter (`outbox.status = dead`)                                                |
| `408`, `429`, `5xx`, network error, timeout | retry with exponential backoff + jitter (1 s → 5 min cap), up to `GATEWAY_WEBHOOK_MAX_ATTEMPTS` |

Delivery is **at-least-once and strictly ordered**: the outbox delivers in insertion order and pauses at the first event that is backing off. Laravel rejects timestamps outside ±300 s and deduplicates by `eventId`.

### Secret rotation

1. Set `GATEWAY_WEBHOOK_SECRET_PREVIOUS=<old>` and `GATEWAY_WEBHOOK_SECRET=<new>` in Laravel; `php artisan config:cache`.
2. Set the new secret in the gateway and restart it.
3. Remove `GATEWAY_WEBHOOK_SECRET_PREVIOUS` once the outbox is empty.

`GATEWAY_API_TOKEN` rotation needs both sides updated together (brief 401s during the switch are retried by Laravel jobs).
