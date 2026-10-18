<?php

namespace App\Actions\Contacts;

use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a contact and syncs its tags by name. Never touches consent.
 */
class SaveContact
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, phone_number?: string, notes?: string|null, tags?: list<string>}  $data
     */
    public function handle(?Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data): Contact {
            $contact ??= new Contact(['source' => ContactSource::Manual]);
            $contact->name = $data['name'];

            if (array_key_exists('notes', $data)) {
                $contact->notes = $data['notes'];
            }

            if (isset($data['phone_number'])) {
                $phoneNumber = (string) PhoneNumber::normalize($data['phone_number']);
                $contact->phone_number = $phoneNumber;
                $contact->wa_jid = PhoneNumber::toJid($phoneNumber);
            }

            $created = ! $contact->exists;
            $contact->save();

            if (array_key_exists('tags', $data)) {
                $contact->tags()->sync(collect($data['tags'])
                    ->map(fn (string $name): string => mb_strtolower(trim($name)))
                    ->filter()
                    ->unique()
                    ->map(fn (string $name): int => Tag::query()->firstOrCreate(['name' => $name])->id)
                    ->all());
            }

            $this->audit->record($created ? 'contact.created' : 'contact.updated', $contact);

            return $contact;
        });
    }
}
