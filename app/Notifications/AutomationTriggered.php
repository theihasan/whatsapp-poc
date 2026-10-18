<?php

namespace App\Notifications;

use App\Models\AutomationRule;
use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal heads-up from an automation rule. Contains no message content.
 */
class AutomationTriggered extends Notification
{
    use Queueable;

    public function __construct(public AutomationRule $rule, public Conversation $conversation) {}

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
            'title' => sprintf('Rule “%s” matched a message from %s', $this->rule->name, $this->conversation->contact->displayName()),
            'rule' => $this->rule->name,
            'conversation_id' => $this->conversation->id,
            'contact' => $this->conversation->contact->displayName(),
        ];
    }
}
