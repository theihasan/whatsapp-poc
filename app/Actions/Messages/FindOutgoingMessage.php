<?php

namespace App\Actions\Messages;

use App\Enums\MessageDirection;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Arr;

/**
 * Locates an outgoing message referenced by a gateway event, by idempotency key first
 * (always known to us) and external id second.
 */
class FindOutgoingMessage
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forUpdate(WhatsAppAccount $account, array $payload): ?Message
    {
        $idempotencyKey = Arr::get($payload, 'idempotencyKey');
        $externalId = Arr::get($payload, 'externalId');

        if (! is_string($idempotencyKey) && ! is_string($externalId)) {
            return null;
        }

        return Message::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('direction', MessageDirection::Outgoing)
            ->where(function ($query) use ($idempotencyKey, $externalId): void {
                if (is_string($idempotencyKey)) {
                    $query->orWhere('idempotency_key', $idempotencyKey);
                }

                if (is_string($externalId)) {
                    $query->orWhere('external_id', $externalId);
                }
            })
            ->lockForUpdate()
            ->first();
    }
}
