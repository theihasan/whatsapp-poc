# Events

## Gateway event envelope (schema version 1)

```json
{
    "eventId": "6b1e…", // UUID, unique; Laravel dedup key
    "type": "message.received",
    "schemaVersion": 1,
    "sessionId": "01J…", // whatsapp_accounts.id
    "occurredAt": "2026-10-18T10:00:00.000Z",
    "payload": {}
}
```

Names are application conventions, not Baileys event names. The mock provider emits the same envelopes in-process. Unknown types are stored and marked `ignored`.

| Type                     | Payload                                                                                                                                                                       | Laravel effect                                                                                                                 |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `session.connecting`     | `{}`                                                                                                                                                                          | → `connecting`                                                                                                                 |
| `session.qr.updated`     | `{qr, expiresInSeconds}`                                                                                                                                                      | → `qr_ready`; QR cached (TTL ≤ `MESSAGING_QR_TTL`), never stored/logged/broadcast                                              |
| `session.connected`      | `{phoneNumber?, jid?, displayName?}`                                                                                                                                          | → `connected` (only on this event)                                                                                             |
| `session.reconnecting`   | `{reason, attempt, retryInMs}`                                                                                                                                                | → `reconnecting`                                                                                                               |
| `session.disconnected`   | `{reason}` (`manual`, `gateway_shutdown`, QR timeout, replaced)                                                                                                               | → `disconnected`                                                                                                               |
| `session.logged_out`     | `{reason}`                                                                                                                                                                    | → `logged_out` (credentials already deleted by the gateway)                                                                    |
| `session.failed`         | `{reason}`                                                                                                                                                                    | → `failed` (gave up / fatal)                                                                                                   |
| `message.received`       | `{externalId, chatType: direct\|group, from:{jid, lid\|null, phoneNumber\|null, pushName\|null}, participant\|null, type, body\|null, rawType\|null, timestamp, media\|null}` | contact / conversation / message; dedup on `(account, externalId)`                                                             |
| `message.status.updated` | `{externalId, idempotencyKey\|null, status: sent\|delivered\|read, timestamp}`                                                                                                | forward-only status advance                                                                                                    |
| `message.failed`         | `{externalId?, idempotencyKey?, reason}`                                                                                                                                      | `failed` unless delivery evidence already exists                                                                               |
| `contact.identified`     | `{lid, jid, phoneNumber}`                                                                                                                                                     | WhatsApp revealed the phone number behind a LID → contact gets `wa_lid`, phone-number `wa_jid`, `phone_number`                 |
| `message.reaction`       | `{externalId, targetExternalId, emoji ('' = removed), chatType, from, participant\|null, timestamp}`                                                                          | one reaction per person stored on the target message (`messages.reactions`); never creates a message or changes unread/preview |

`from.jid` is the phone-number JID when known, otherwise the LID; `from.lid` carries the LID when one exists. Before publishing, the gateway completes LID-only senders from Baileys' local LID→phone mapping store. `from.phoneNumber` stays null only when WhatsApp has not shared the mapping; Laravel never derives a number from a LID.

Group chats: `chatType` is `group`, `from` is the group (`…@g.us`, `pushName` = group subject, cached 1 h in the gateway) and `participant` is the sender (resolved from LID like direct senders). Laravel stores the group as an `is_group` contact and the sender on the message (`sender_jid`, `sender_name`, `metadata.sender_phone`); members are not created as contacts, and group chats never trigger automations or STOP opt-out.

`media` (incoming): `{mediaId, mimeType, fileName, size}` or `{error}` when the download failed.

## Device state machine

| From           | Allowed to                                                                               |
| -------------- | ---------------------------------------------------------------------------------------- |
| `created`      | `connecting`, `failed`                                                                   |
| `connecting`   | `qr_ready`, `connected`, `reconnecting`, `disconnected`, `logged_out`, `failed`          |
| `qr_ready`     | `qr_ready` (new code), `connecting`, `connected`, `disconnected`, `logged_out`, `failed` |
| `connected`    | `connecting` (gateway restore), `reconnecting`, `disconnected`, `logged_out`, `failed`   |
| `reconnecting` | `connecting`, `qr_ready`, `connected`, `disconnected`, `logged_out`, `failed`            |
| `disconnected` | `connecting`, `logged_out`                                                               |
| `logged_out`   | `connecting`                                                                             |
| `failed`       | `connecting`, `logged_out`                                                               |

Any other transition is refused and the event is marked `ignored` (e.g. a late QR after `connected`). Source: `App\Enums\DeviceStatus`.

## Message status

Outgoing: `queued → processing → gateway_accepted → sent → delivered → read`, plus `failed` and `unknown`. Incoming messages are `received`.

- Status only moves forward along the ladder; late/out-of-order receipts are ignored.
- `sent`/`delivered`/`read` evidence overrides `failed`/`unknown` (the message evidently went out).
- `gateway_accepted` ≠ delivered: it only means WhatsApp Web accepted the send.
- `unknown` = the request may have been sent (timeout). It is never resent automatically; a manual retry reuses the idempotency key, so the gateway returns the original message id if it did go out.

## Browser broadcasts (Reverb, private channels)

| Channel              | Event                                    | Payload                                                                   |
| -------------------- | ---------------------------------------- | ------------------------------------------------------------------------- |
| `devices`            | `DeviceStatusChanged`                    | `{id, status, phone_number, display_name, last_error, status_changed_at}` |
| `conversations`      | `ConversationUpdated`                    | `{conversation: ConversationResource}`                                    |
| `conversations.{id}` | `MessageCreated`, `MessageStatusUpdated` | `{message: MessageResource}`                                              |

All are dispatched after the database transaction commits. Raw gateway payloads are never rebroadcast. Channel authorization: `routes/channels.php`.
