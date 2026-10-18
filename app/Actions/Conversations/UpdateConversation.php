<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Changes a conversation's status and/or assignee, recording both in the audit log.
 */
class UpdateConversation
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{status?: string, assigned_user_id?: int|null}  $changes
     */
    public function handle(Conversation $conversation, array $changes): Conversation
    {
        return DB::transaction(function () use ($conversation, $changes): Conversation {
            if (array_key_exists('status', $changes)) {
                $conversation->status = ConversationStatus::from($changes['status']);
            }

            if (array_key_exists('assigned_user_id', $changes)) {
                $conversation->assigned_user_id = $changes['assigned_user_id'];
            }

            $dirty = $conversation->getDirty();

            if ($dirty === []) {
                return $conversation;
            }

            $conversation->save();

            if (array_key_exists('assigned_user_id', $dirty)) {
                $this->audit->record('conversation.assigned', $conversation, ['assigned_user_id' => $conversation->assigned_user_id]);
            }

            if (array_key_exists('status', $dirty)) {
                $this->audit->record('conversation.status_changed', $conversation, ['status' => $conversation->status->value]);
            }

            ConversationUpdated::dispatch($conversation);

            return $conversation;
        });
    }
}
