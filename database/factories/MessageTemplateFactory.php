<?php

namespace Database\Factories;

use App\Enums\TemplateStatus;
use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement(['Greeting', 'Support', 'Follow-up']),
            'body' => 'Hi {{contact_name}}, thanks for contacting {{company_name}}.',
            'status' => TemplateStatus::Active,
        ];
    }
}
