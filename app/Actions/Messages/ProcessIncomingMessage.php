<?php

namespace App\Actions\Messages;

use App\Actions\Contacts\LinkContactIdentity;
use App\Actions\Contacts\RevokeConsent;
use App\Enums\ContactSource;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\MessagingProviderName;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Jobs\DownloadIncomingAttachment;
use App\Jobs\FetchContactAvatar;
use App\Jobs\RunAutomations;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\NewConversationStarted;
use App\Services\WorkspaceSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Turns a normalized `message.received` payload into contact / conversation / message rows.
 * Idempotent on (whatsapp_account_id, external_id): returns null for duplicates.
 */
class ProcessIncomingMessage
{
    /**
     * Messages that withdraw every active consent (compared case-insensitively).
     */
    public const OPT_OUT_KEYWORDS = ['stop', 'unsubscribe', 'stop all', 'opt out', 'optout'];

    public function __construct(
        private RevokeConsent $revokeConsent,
        private WorkspaceSettings $settings,
        private LinkContactIdentity $linkContactIdentity,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WhatsAppAccount $account, array $payload): ?Message
    {
        $externalId = Arr::get($payload, 'externalId');
        $jid = Arr::get($payload, 'from.jid');

        if (! is_string($externalId) || $externalId === '' || ! is_string($jid) || $jid === '') {
            throw new \InvalidArgumentException('message.received requires externalId and from.jid.');
        }

        $type = MessageType::tryFrom((string) Arr::get($payload, 'type')) ?? MessageType::Unsupported;
        $body = Arr::get($payload, 'body') ?? Arr::get($payload, 'caption');
        $body = is_string($body) ? mb_substr($body, 0, 65535) : null;
        $media = Arr::get($payload, 'media');

        try {
            return DB::transaction(function () use ($account, $payload, $externalId, $jid, $type, $body, $media): ?Message {
                if (Message::query()->where('whatsapp_account_id', $account->id)->where('external_id', $externalId)->exists()) {
                    return null;
                }

                $contact = $this->resolveContact($jid, $payload);

                if ($account->provider === MessagingProviderName::UnofficialWeb && $contact->avatarIsStale()) {
                    FetchContactAvatar::dispatch($contact, $account)->afterCommit();
                }

                $conversation = Conversation::query()->firstOrCreate(
                    ['whatsapp_account_id' => $account->id, 'contact_id' => $contact->id],
                    ['status' => ConversationStatus::Open],
                );

                $message = $conversation->messages()->create([
                    'whatsapp_account_id' => $account->id,
                    'contact_id' => $contact->id,
                    'direction' => MessageDirection::Incoming,
                    'type' => $type,
                    'body' => $body,
                    'external_id' => $externalId,
                    'status' => MessageStatus::Received,
                    'metadata' => array_filter([
                        'timestamp' => Arr::get($payload, 'timestamp'),
                        'unsupported_type' => $type === MessageType::Unsupported ? Arr::get($payload, 'type') : null,
                    ]),
                ]);

                $conversation->fill([
                    'status' => ConversationStatus::Open,
                    'last_message_at' => $message->created_at,
                    'last_message_preview' => self::preview($type, $body),
                ]);
                $conversation->unread_count++;
                $conversation->save();

                $contact->forceFill(['last_activity_at' => now()])->save();

                if ($type === MessageType::Text && in_array(mb_strtolower(trim((string) $body)), self::OPT_OUT_KEYWORDS, true)) {
                    $this->revokeConsent->handle($contact, null, 'opt-out keyword received');
                }

                if (is_array($media) && is_string($media['mediaId'] ?? null)) {
                    DownloadIncomingAttachment::dispatch($message, $media['mediaId'])->afterCommit();
                } elseif (is_array($media) && isset($media['error'])) {
                    $message->update(['metadata' => [...($message->metadata ?? []), 'media_error' => 'The attachment could not be downloaded from WhatsApp.']]);
                }

                MessageCreated::dispatch($message);
                ConversationUpdated::dispatch($conversation);
                RunAutomations::dispatch($message)->afterCommit();

                if ($conversation->wasRecentlyCreated && $this->settings->get('notify_on_new_conversation')) {
                    DB::afterCommit(fn () => Notification::send(User::query()->get(), new NewConversationStarted($conversation)));
                }

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public static function preview(MessageType $type, ?string $body): string
    {
        $label = match ($type) {
            MessageType::Image => '📷 Photo',
            MessageType::Document => '📄 Document',
            MessageType::Audio => '🎤 Audio',
            MessageType::Unsupported => 'Unsupported message',
            MessageType::Text => null,
        };

        $text = Str::limit(trim((string) $body), 120);

        return $label === null ? $text : trim($label.' '.$text);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveContact(string $jid, array $payload): Contact
    {
        $lid = Arr::get($payload, 'from.lid');
        $lid = is_string($lid) && $lid !== '' ? $lid : null;
        $phoneNumber = Arr::get($payload, 'from.phoneNumber');
        $phoneNumber = is_string($phoneNumber) && $phoneNumber !== '' ? $phoneNumber : null;
        $pushName = Arr::get($payload, 'from.pushName');

        $contact = Contact::query()
            ->where('wa_jid', $jid)
            ->when($lid, fn ($query, string $lid) => $query->orWhere('wa_jid', $lid)->orWhere('wa_lid', $lid))
            ->first();

        if ($contact === null) {
            return Contact::query()->create([
                'wa_jid' => $jid,
                'wa_lid' => $lid,
                'name' => is_string($pushName) && $pushName !== '' ? mb_substr($pushName, 0, 255) : null,
                'phone_number' => $phoneNumber,
                'source' => ContactSource::IncomingMessage,
            ]);
        }

        if ($lid !== null && $phoneNumber !== null && $jid !== $lid) {
            return $this->linkContactIdentity->handle($lid, $jid, $phoneNumber) ?? $contact;
        }

        if ($contact->phone_number === null && $phoneNumber !== null) {
            $contact->update(['phone_number' => $phoneNumber]);
        }

        return $contact;
    }
}
