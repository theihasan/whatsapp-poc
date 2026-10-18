<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'whatsapp_account_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->firstOrFail()->whatsapp_account_id,
            'contact_id' => fn (array $attributes) => Conversation::query()->whereKey($attributes['conversation_id'])->firstOrFail()->contact_id,
            'direction' => MessageDirection::Incoming,
            'type' => MessageType::Text,
            'body' => fake()->sentence(),
            'external_id' => 'MOCK'.Str::upper(Str::random(16)),
            'status' => MessageStatus::Received,
        ];
    }

    public function outgoing(MessageStatus $status = MessageStatus::Queued): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Outgoing,
            'status' => $status,
            'external_id' => null,
            'idempotency_key' => (string) Str::ulid(),
        ]);
    }
}
