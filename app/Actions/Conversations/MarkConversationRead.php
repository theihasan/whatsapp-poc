<?php

namespace App\Actions\Conversations;

use App\Events\ConversationUpdated;
use App\Models\Conversation;

class MarkConversationRead
{
    public function handle(Conversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
            return;
        }

        $conversation->update(['unread_count' => 0]);
        ConversationUpdated::dispatch($conversation);
    }
}
