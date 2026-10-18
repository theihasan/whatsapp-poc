<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use App\Models\ContactConsent;
use App\Services\AuditLogger;

class RevokeConsent
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Revoke one consent record, or every active one for the contact when null.
     */
    public function handle(Contact $contact, ?ContactConsent $consent, string $reason): int
    {
        $revoked = $contact->consents()
            ->active()
            ->when($consent, fn ($query) => $query->whereKey($consent->id))
            ->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            $this->audit->record('contact.consent_revoked', $contact, ['count' => $revoked, 'reason' => $reason]);
        }

        return $revoked;
    }
}
