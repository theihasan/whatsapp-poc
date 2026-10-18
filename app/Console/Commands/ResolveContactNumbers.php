<?php

namespace App\Console\Commands;

use App\Actions\Contacts\LinkContactIdentity;
use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use App\Exceptions\ProviderUnavailableException;
use App\Models\Contact;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\UnofficialWebProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fills in phone numbers for contacts known only by a WhatsApp LID, using the mappings
 * the connected WhatsApp Web devices have already received. Never guesses.
 */
#[Signature('app:resolve-contact-numbers')]
#[Description('Resolve phone numbers for LID-only contacts from connected WhatsApp Web devices')]
class ResolveContactNumbers extends Command
{
    public function handle(UnofficialWebProvider $provider, LinkContactIdentity $link): int
    {
        $devices = WhatsAppAccount::query()
            ->where('provider', MessagingProviderName::UnofficialWeb)
            ->where('status', DeviceStatus::Connected)
            ->get();

        if ($devices->isEmpty()) {
            $this->warn('No connected WhatsApp Web device to ask.');

            return self::SUCCESS;
        }

        $resolved = 0;

        Contact::query()
            ->whereNull('phone_number')
            ->where(fn (Builder $query) => $query->where('wa_jid', 'like', '%@lid')->orWhere('wa_lid', 'like', '%@lid'))
            ->chunkById(500, function ($contacts) use ($devices, $provider, $link, &$resolved): void {
                $lids = array_values($contacts->map(fn (Contact $contact): string => (string) ($contact->wa_lid ?? $contact->wa_jid))->all());

                foreach ($devices as $device) {
                    try {
                        $identities = $provider->resolveContactIdentities($device, $lids);
                    } catch (ProviderUnavailableException $exception) {
                        $this->warn("{$device->name}: {$exception->getMessage()}");

                        continue;
                    }

                    foreach ($identities as $identity) {
                        if ($link->handle($identity['lid'], $identity['jid'], $identity['phoneNumber']) !== null) {
                            $resolved++;
                        }
                    }
                }
            });

        $this->info("Resolved {$resolved} contact(s).");

        return self::SUCCESS;
    }
}
