<?php

namespace App\Actions\Messages;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\MessageStatusUpdated;
use App\Exceptions\MessageActionException;
use App\Jobs\SendOutgoingMessage;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Explicit, user-initiated retry of a failed or unknown message. The idempotency key is
 * kept, so if an "unknown" send actually went out, idempotent providers return the
 * original message instead of sending a duplicate.
 */
class RetryOutgoingMessage
{
    public function handle(Message $message): void
    {
        DB::transaction(function () use ($message): void {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);

            if ($locked->direction !== MessageDirection::Outgoing || ! $locked->status->isRetryable()) {
                throw new MessageActionException('Only failed or unconfirmed messages can be retried.');
            }

            $locked->update(['status' => MessageStatus::Queued, 'failure_reason' => null]);

            MessageStatusUpdated::dispatch($locked);
            SendOutgoingMessage::dispatch($locked)->afterCommit();
        });
    }
}
