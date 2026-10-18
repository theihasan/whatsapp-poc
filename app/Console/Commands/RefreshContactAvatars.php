<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use App\Jobs\FetchContactAvatar;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Queues profile-picture checks for contacts you already talk to through a connected
 * WhatsApp Web device. Bounded by --limit; never looks up people you have no
 * conversation with.
 */
#[Signature('app:refresh-contact-avatars {--limit=50 : Maximum contacts to queue}')]
#[Description('Queue WhatsApp profile picture refreshes for contacts with stale or missing avatars')]
class RefreshContactAvatars extends Command
{
    public function handle(): int
    {
        $queued = 0;

        Contact::query()
            ->whereNotNull('wa_jid')
            ->where(fn (Builder $query) => $query->whereNull('avatar_checked_at')->orWhere('avatar_checked_at', '<', now()->subDays(Contact::AVATAR_REFRESH_DAYS)))
            ->whereHas('conversations.whatsappAccount', fn (Builder $query) => $query
                ->where('provider', MessagingProviderName::UnofficialWeb)
                ->where('status', DeviceStatus::Connected))
            ->with(['conversations.whatsappAccount'])
            ->orderByDesc('last_activity_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (Contact $contact) use (&$queued): void {
                $conversation = $contact->conversations->first(
                    fn (Conversation $conversation): bool => $conversation->whatsappAccount->provider === MessagingProviderName::UnofficialWeb
                        && $conversation->whatsappAccount->status === DeviceStatus::Connected,
                );

                if ($conversation !== null) {
                    FetchContactAvatar::dispatch($contact, $conversation->whatsappAccount);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} avatar refresh(es).");

        return self::SUCCESS;
    }
}
