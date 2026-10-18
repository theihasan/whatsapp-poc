<?php

namespace App\Actions\Contacts;

use App\Events\ConversationUpdated;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Records that a WhatsApp LID belongs to a phone number. The contact keeps the LID in
 * `wa_lid` and switches `wa_jid` to the phone-number JID, so messages addressed either
 * way resolve to the same person.
 */
class LinkContactIdentity
{
    public function handle(string $lid, string $jid, string $phoneNumber): ?Contact
    {
        return DB::transaction(function () use ($lid, $jid, $phoneNumber): ?Contact {
            $contact = Contact::query()
                ->where('wa_lid', $lid)
                ->orWhere('wa_jid', $lid)
                ->orWhere('wa_jid', $jid)
                ->orderByRaw('case when wa_lid = ? or wa_jid = ? then 0 else 1 end', [$lid, $lid])
                ->lockForUpdate()
                ->first();

            if ($contact === null) {
                return null;
            }

            $jidTakenByOther = Contact::query()->where('wa_jid', $jid)->whereKeyNot($contact->id)->exists();
            $lidTakenByOther = Contact::query()->where('wa_lid', $lid)->whereKeyNot($contact->id)->exists();

            if (! $lidTakenByOther) {
                $contact->wa_lid = $lid;
            }

            if (! $jidTakenByOther) {
                $contact->wa_jid = $jid;
            }

            $contact->phone_number ??= $phoneNumber;

            if (! $contact->isDirty()) {
                return $contact;
            }

            $contact->save();

            $contact->conversations()->get()->each(fn (Conversation $conversation) => ConversationUpdated::dispatch($conversation));

            return $contact;
        });
    }
}
