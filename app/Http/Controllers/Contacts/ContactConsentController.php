<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\RevokeConsent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreConsentRequest;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ContactConsentController extends Controller
{
    /**
     * Record an explicit opt-in with its source and evidence.
     */
    public function store(StoreConsentRequest $request, Contact $contact, AuditLogger $audit): RedirectResponse
    {
        $consent = $contact->consents()->create([
            ...$request->validated(),
            'recorded_by_user_id' => $request->user()?->id,
        ]);

        $audit->record('contact.consent_recorded', $contact, ['scope' => $consent->scope, 'source' => $consent->source]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Consent recorded.')]);

        return back();
    }

    public function destroy(Contact $contact, ContactConsent $consent, RevokeConsent $revoke): RedirectResponse
    {
        $revoke->handle($contact, $consent, 'revoked by user');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Consent revoked.')]);

        return back();
    }
}
