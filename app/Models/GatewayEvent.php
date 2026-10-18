<?php

namespace App\Models;

use App\Enums\GatewayEventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GatewayEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A normalized event received from the gateway (or emitted by the mock provider),
 * persisted before it is processed.
 *
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string $session_id
 * @property int $schema_version
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed> $payload
 * @property GatewayEventStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['event_id', 'type', 'session_id', 'schema_version', 'occurred_at', 'payload', 'status', 'attempts', 'error', 'processed_at'])]
class GatewayEvent extends Model
{
    /** @use HasFactory<GatewayEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'status' => GatewayEventStatus::class,
            'processed_at' => 'datetime',
        ];
    }
}
