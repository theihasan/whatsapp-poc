<?php

namespace App\Actions\Messages;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Jobs\SendOutgoingMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists an outgoing message (status `queued`, fresh idempotency key) before any
 * delivery attempt, then hands it to the queue after commit.
 */
class QueueOutgoingMessage
{
    public function handle(
        Conversation $conversation,
        ?User $sender,
        ?string $body,
        ?UploadedFile $attachment = null,
        bool $isAutomated = false,
    ): Message {
        $body = $body === null || trim($body) === '' ? null : $body;
        $type = $attachment === null ? MessageType::Text : self::typeFor((string) $attachment->getMimeType());
        $storedPath = $attachment?->store('attachments/'.now()->format('Y/m'), (string) config('messaging.attachments.disk'));

        return DB::transaction(function () use ($conversation, $sender, $body, $attachment, $type, $storedPath, $isAutomated): Message {
            $message = $conversation->messages()->create([
                'whatsapp_account_id' => $conversation->whatsapp_account_id,
                'contact_id' => $conversation->contact_id,
                'user_id' => $sender?->id,
                'direction' => MessageDirection::Outgoing,
                'type' => $type,
                'body' => $body,
                'idempotency_key' => (string) Str::ulid(),
                'status' => MessageStatus::Queued,
                'is_automated' => $isAutomated,
            ]);

            if ($attachment !== null && is_string($storedPath)) {
                $message->attachments()->create([
                    'disk' => config('messaging.attachments.disk'),
                    'path' => $storedPath,
                    'mime_type' => (string) $attachment->getMimeType(),
                    'size' => (int) $attachment->getSize(),
                    'original_name' => mb_substr($attachment->getClientOriginalName(), 0, 255),
                ]);
            }

            $conversation->fill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => ProcessIncomingMessage::preview($type, $body),
                'unread_count' => 0,
                'suggested_reply' => $isAutomated ? $conversation->suggested_reply : null,
            ])->save();

            MessageCreated::dispatch($message);
            ConversationUpdated::dispatch($conversation);
            SendOutgoingMessage::dispatch($message)->afterCommit();

            return $message;
        });
    }

    public static function typeFor(string $mimeType): MessageType
    {
        return match (true) {
            in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true) => MessageType::Image,
            str_starts_with($mimeType, 'audio/') => MessageType::Audio,
            default => MessageType::Document,
        };
    }
}
