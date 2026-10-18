<?php

namespace App\DTOs;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * A normalized, versioned gateway event (see docs/events.md).
 */
final readonly class GatewayEventData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public int $schemaVersion,
        public string $sessionId,
        public CarbonInterface $occurredAt,
        public array $payload,
    ) {}

    /**
     * @param  array{eventId: string, type: string, schemaVersion: int, sessionId: string, occurredAt: string, payload: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['eventId'],
            type: $data['type'],
            schemaVersion: $data['schemaVersion'],
            sessionId: $data['sessionId'],
            occurredAt: Date::parse($data['occurredAt']),
            payload: $data['payload'],
        );
    }
}
