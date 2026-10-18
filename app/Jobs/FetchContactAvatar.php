<?php

namespace App\Jobs;

use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use App\Events\ConversationUpdated;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\UnofficialWebProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a contact's WhatsApp profile picture to private storage. Checked at most once
 * per Contact::AVATAR_REFRESH_DAYS; a hidden or missing picture is remembered as checked.
 */
class FetchContactAvatar implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 300];

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(public Contact $contact, public WhatsAppAccount $account) {}

    public function uniqueId(): string
    {
        return $this->contact->id;
    }

    public function handle(UnofficialWebProvider $provider): void
    {
        $contact = $this->contact->fresh();

        if ($contact === null
            || $contact->wa_jid === null
            || ! $contact->avatarIsStale()
            || $this->account->provider !== MessagingProviderName::UnofficialWeb
            || $this->account->fresh()?->status !== DeviceStatus::Connected) {
            return;
        }

        $picture = $provider->profilePicture($this->account, $contact->wa_jid);
        $disk = Storage::disk((string) config('messaging.attachments.disk'));
        $extension = $picture === null ? null : (self::EXTENSIONS[$picture['mime_type']] ?? null);

        if ($picture === null || $extension === null) {
            if ($contact->avatar_path !== null) {
                $disk->delete($contact->avatar_path);
            }

            $contact->update(['avatar_path' => null, 'avatar_checked_at' => now()]);
        } else {
            $path = "avatars/{$contact->id}.{$extension}";
            $disk->put($path, $picture['data']);
            $contact->update(['avatar_path' => $path, 'avatar_checked_at' => now()]);
        }

        $contact->conversations()->get()->each(fn (Conversation $conversation) => ConversationUpdated::dispatch($conversation));
    }
}
