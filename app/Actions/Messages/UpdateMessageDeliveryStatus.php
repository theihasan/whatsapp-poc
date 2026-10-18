<?php

namespace App\Actions\Messages;

use App\Enums\MessageStatus;
use App\Events\MessageStatusUpdated;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Applies `message.status.updated`. Forward-only: late or out-of-order events never move a
 * message backwards, and delivery evidence overrides failed/unknown.
 */
class UpdateMessageDeliveryStatus
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WhatsAppAccount $account, array $payload): bool
    {
        $status = MessageStatus::tryFrom((string) Arr::get($payload, 'status'));

        if (! in_array($status, [MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read], true)) {
            return false;
        }

        return DB::transaction(function () use ($account, $payload, $status): bool {
            $message = FindOutgoingMessage::forUpdate($account, $payload);

            if ($message === null || ! $message->status->canAdvanceTo($status)) {
                return false;
            }

            $externalId = Arr::get($payload, 'externalId');

            if ($message->external_id === null && is_string($externalId)) {
                $message->external_id = $externalId;
            }

            $message->status = $status;
            $message->failure_reason = null;
            $message->sent_at ??= now();

            if ($status !== MessageStatus::Sent) {
                $message->delivered_at ??= now();
            }

            if ($status === MessageStatus::Read) {
                $message->read_at ??= now();
            }

            $message->save();

            MessageStatusUpdated::dispatch($message);

            return true;
        });
    }
}
