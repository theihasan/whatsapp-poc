<?php

namespace App\Notifications;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A contact wrote to a device for the first time. Contains no message content.
 */
class NewConversationStarted extends Notification
{
    use Queueable;

    public function __construct(public Conversation $conversation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New conversation with '.$this->conversation->contact->displayName(),
            'conversation_id' => $this->conversation->id,
        ];
    }
}
