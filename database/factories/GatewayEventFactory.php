<?php

namespace Database\Factories;

use App\Enums\GatewayEventStatus;
use App\Models\GatewayEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GatewayEvent>
 */
class GatewayEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'type' => 'session.connected',
            'session_id' => (string) Str::ulid(),
            'schema_version' => 1,
            'occurred_at' => now(),
            'payload' => [],
            'status' => GatewayEventStatus::Pending,
        ];
    }
}
