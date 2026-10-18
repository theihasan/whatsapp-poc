<?php

namespace App\Actions\Gateway;

use App\DTOs\GatewayEventData;
use App\Enums\GatewayEventStatus;
use App\Jobs\ProcessGatewayEvent;
use App\Models\GatewayEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Persists a gateway event before any processing so it survives crashes and can be
 * replayed. Returns null when the event id was already recorded (duplicate delivery).
 */
class RecordGatewayEvent
{
    public function handle(GatewayEventData $data): ?GatewayEvent
    {
        try {
            $event = GatewayEvent::query()->create([
                'event_id' => $data->eventId,
                'type' => $data->type,
                'schema_version' => $data->schemaVersion,
                'session_id' => $data->sessionId,
                'occurred_at' => $data->occurredAt,
                'payload' => $data->payload,
                'status' => GatewayEventStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        ProcessGatewayEvent::dispatch($event)->afterCommit();

        return $event;
    }
}
