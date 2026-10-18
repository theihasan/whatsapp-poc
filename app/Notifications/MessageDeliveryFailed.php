<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An outgoing message failed or could not be confirmed. Contains no message content.
 */
class MessageDeliveryFailed extends Notification
{
    use Queueable;

    public function __construct(public Message $message) {}

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
            'title' => sprintf('Message to %s %s', $this->message->contact->displayName(), $this->message->status->value === 'unknown' ? 'is unconfirmed' : 'failed'),
            'conversation_id' => $this->message->conversation_id,
        ];
    }
}
