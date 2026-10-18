<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'whatsapp_account_id' => WhatsAppAccount::factory(),
            'contact_id' => Contact::factory(),
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
            'last_message_preview' => fake()->sentence(),
            'unread_count' => 0,
        ];
    }
}
