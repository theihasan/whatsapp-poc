<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use App\Models\WhatsAppAccount;
use App\Support\FakePhone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppAccount>
 */
class WhatsAppAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Support line', 'Sales line', 'Front desk']).' '.fake()->numerify('##'),
            'provider' => MessagingProviderName::Mock,
            'status' => DeviceStatus::Created,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeviceStatus::Connected,
            'phone_number' => FakePhone::number(),
            'display_name' => fake()->company(),
            'connected_at' => now(),
            'status_changed_at' => now(),
        ]);
    }

    public function unofficial(): static
    {
        return $this->state(fn (array $attributes) => ['provider' => MessagingProviderName::UnofficialWeb]);
    }
}
