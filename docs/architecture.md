# Architecture

A modular Laravel monolith with a small Node.js sidecar (`services/whatsapp-gateway`).

```
Browser (React 19 + Inertia 3)
   │  Inertia visits / forms (session auth, CSRF)        ▲ Reverb (private channels)
   ▼                                                      │
Laravel 13 ── MySQL (business data, source of truth)      │
   │  ├─ Redis: cache, sessions, queues (Horizon), QR TTL │
   │  ├─ Jobs: SendOutgoingMessage, ProcessGatewayEvent, RunAutomations
   │  └─ MessagingProvider contract
   │        ├─ MockMessagingProvider      (in-process, deterministic)
   │        ├─ UnofficialWebProvider ──HTTP (bearer + correlation id)──▶ Gateway
   │        └─ OfficialCloudProvider ──HTTPS──▶ Meta WhatsApp Cloud API
   ▲
   └── Signed webhook (HMAC-SHA256, timestamp, event id) ◀── Gateway
                                                              │ Baileys socket per session
                                                              ▼
                                                        WhatsApp Web
```

## Responsibilities

| Laravel owns | Gateway owns |
|---|---|
| Users, auth, settings, audit log | WhatsApp Web protocol (Baileys) |
| Accounts (devices), contacts, consent, conversations, messages | QR generation, connection lifecycle, reconnect/backoff |
| Message state machine, idempotency, deduplication | Encrypted auth-state store (creds + Signal keys) |
| Automations, templates, dashboard | Translating protocol events into normalized events |
| Broadcasting to the browser | Durable webhook outbox with retries |

The gateway holds no business data beyond what it needs to deliver events: session ids, auth state, and a pending-webhook outbox.

## Outbound flow

1. `POST /conversations/{conversation}/messages` → `QueueOutgoingMessage` action writes a `messages` row (`queued`, new `idempotency_key`) inside a transaction and dispatches `SendOutgoingMessage` after commit.
2. Job sets `processing`, calls `provider->sendMessage()` with the idempotency key.
3. Provider result:
   - accepted → `gateway_accepted` + `external_id`
   - permanent rejection → `failed` + reason
   - timeout / connection error after the request may have been sent → `unknown` (no automatic resend; user can retry explicitly, which reuses the idempotency key so the gateway can deduplicate).
4. Later `message.status.updated` events advance `sent → delivered → read`. Status only moves forward (out-of-order events are ignored).

## Inbound flow

1. Gateway normalizes a Baileys `messages.upsert` into `message.received` and stores it in its outbox.
2. Outbox POSTs to `POST /api/gateway/events` with HMAC signature; retries with exponential backoff until a 2xx.
3. `GatewayWebhookController` verifies signature + timestamp, inserts `gateway_events` (unique `event_id`), dispatches `ProcessGatewayEvent`, returns `202`. Duplicate → `200 duplicate`.
4. `ProcessGatewayEvent` routes by type to actions (`ProcessIncomingMessage`, `UpdateMessageDeliveryStatus`, `ApplyDeviceStatus`). Incoming messages are deduplicated by `(whatsapp_account_id, external_id)`.
5. Domain events broadcast after commit on private Reverb channels; React updates via `useEcho`.

## Device state machine

```
created ─▶ connecting ─▶ qr_ready ─▶ connected
              ▲  │          │ ▲          │
              │  ▼          ▼ │          ▼
           reconnecting ◀───┴─┴──── disconnected
              │
              ▼
           failed            any ─▶ logged_out (revoked / unlinked on phone)
```

Valid transitions are encoded in `App\Enums\DeviceStatus::canTransitionTo()` and documented in `docs/events.md`. `connected` is set only on a gateway `session.connected` event, never on QR display.

## Real-time channels

| Channel | Events | Payload policy |
|---|---|---|
| `private-devices` | `DeviceStatusChanged` | account id, status, phone/display name, last error |
| `private-conversations` | `ConversationUpdated` | list-row fields: preview (truncated), unread, assignee, status |
| `private-conversations.{id}` | `MessageCreated`, `MessageStatusUpdated` | message resource fields only |

No gateway payload is rebroadcast.

## Horizontal scaling (not implemented)

The gateway is single-instance: one process owns all sockets. Scaling out would need sticky session ownership (e.g. Redis lease per session id), a shared auth-state store, and an outbox that is not file-local.
