<?php

namespace App\Actions\Messages;

use App\Enums\MessageStatus;
use App\Events\MessageStatusUpdated;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Applies `message.failed` from the gateway. Ignored once there is delivery evidence.
 */
class MarkOutgoingMessageFailed
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WhatsAppAccount $account, array $payload): bool
    {
        return DB::transaction(function () use ($account, $payload): bool {
            $message = FindOutgoingMessage::forUpdate($account, $payload);

            if ($message === null || ($message->status->rank() ?? 0) >= MessageStatus::Sent->rank()) {
                return false;
            }

            $message->status = MessageStatus::Failed;
            $message->failure_reason = mb_substr((string) Arr::get($payload, 'reason', 'The gateway reported a failure.'), 0, 1000);
            $message->save();

            MessageStatusUpdated::dispatch($message);

            return true;
        });
    }
}
