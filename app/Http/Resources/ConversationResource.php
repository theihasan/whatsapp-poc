<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'whatsapp_account_id' => $this->whatsapp_account_id,
            'status' => $this->status->value,
            'unread_count' => $this->unread_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->last_message_preview,
            'contact' => [
                'id' => $this->contact->id,
                'name' => $this->contact->displayName(),
                'phone_number' => $this->contact->phone_number,
                'avatar_url' => ContactResource::avatarUrl($this->contact),
            ],
            'assignee' => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ],
        ];
    }
}
