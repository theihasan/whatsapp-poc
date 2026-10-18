<?php

namespace App\Console\Commands;

use App\Enums\GatewayEventStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\MessageStatusUpdated;
use App\Jobs\ProcessGatewayEvent;
use App\Jobs\SendOutgoingMessage;
use App\Models\GatewayEvent;
use App\Models\Message;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recovers work that was lost between queue and database (e.g. Redis flushed, worker
 * killed). Safe to run repeatedly: every re-dispatched job is idempotent.
 */
#[Signature('app:reconcile-messaging')]
#[Description('Re-dispatch stuck gateway events and outgoing messages; flag interrupted sends as unknown')]
class ReconcileMessaging extends Command
{
    public const STALE_EVENT_MINUTES = 10;

    public const STALE_QUEUED_MINUTES = 30;

    public const STALE_PROCESSING_MINUTES = 15;

    public function handle(): int
    {
        $events = GatewayEvent::query()
            ->where('status', GatewayEventStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(self::STALE_EVENT_MINUTES))
            ->orderBy('id')
            ->limit(500)
            ->get();
        $events->each(fn (GatewayEvent $event) => ProcessGatewayEvent::dispatch($event));

        $queued = Message::query()
            ->where('direction', MessageDirection::Outgoing)
            ->where('status', MessageStatus::Queued)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_QUEUED_MINUTES))
            ->limit(500)
            ->get();
        $queued->each(fn (Message $message) => SendOutgoingMessage::dispatch($message));

        $interrupted = Message::query()
            ->where('direction', MessageDirection::Outgoing)
            ->where('status', MessageStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->limit(500)
            ->get();

        foreach ($interrupted as $message) {
            $message->update([
                'status' => MessageStatus::Unknown,
                'failure_reason' => 'Delivery was interrupted; check the phone before retrying.',
            ]);
            MessageStatusUpdated::dispatch($message);
        }

        $this->info(sprintf('Re-dispatched %d events, %d queued messages; flagged %d interrupted sends.', $events->count(), $queued->count(), $interrupted->count()));

        return self::SUCCESS;
    }
}
