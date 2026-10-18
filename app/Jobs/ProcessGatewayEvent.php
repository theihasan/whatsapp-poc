<?php

namespace App\Jobs;

use App\Actions\Gateway\HandleGatewayEvent;
use App\Enums\GatewayEventStatus;
use App\Models\GatewayEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes one persisted gateway event. Events of the same session are serialized so
 * ordering-sensitive updates (status transitions) do not race each other.
 */
class ProcessGatewayEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $maxExceptions = 4;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(public GatewayEvent $event)
    {
        $this->onQueue('events');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('gateway-session:'.$this->event->session_id))->releaseAfter(2)->expireAfter(90)];
    }

    public function handle(HandleGatewayEvent $handler): void
    {
        $event = $this->event->fresh();

        if ($event === null || $event->status !== GatewayEventStatus::Pending) {
            return;
        }

        $event->increment('attempts');

        $applied = $handler->handle($event);

        $event->update([
            'status' => $applied ? GatewayEventStatus::Processed : GatewayEventStatus::Ignored,
            'error' => null,
            'processed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->event->update([
            'status' => GatewayEventStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'Unknown failure', 1000),
        ]);
    }
}
