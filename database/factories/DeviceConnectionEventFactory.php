<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\DeviceConnectionEvent;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceConnectionEvent>
 */
class DeviceConnectionEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_account_id' => WhatsAppAccount::factory(),
            'from_status' => DeviceStatus::Created,
            'to_status' => DeviceStatus::Connecting,
            'reason' => null,
            'occurred_at' => now(),
        ];
    }
}
