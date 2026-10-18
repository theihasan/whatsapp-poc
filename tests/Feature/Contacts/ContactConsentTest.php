<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;

it('records consent with its source, evidence and recorder', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create();

    $this->actingAs($user)->post(route('contacts.consents.store', $contact), [
        'scope' => 'marketing',
        'source' => 'web_form',
        'evidence' => 'Newsletter form #881',
        'consented_at' => now()->subDay()->toDateTimeString(),
    ])->assertRedirect();

    expect($contact->consents()->sole())
        ->scope->toBe('marketing')
        ->evidence->toBe('Newsletter form #881')
        ->recorded_by_user_id->toBe($user->id)
        ->revoked_at->toBeNull();
});

it('requires evidence for consent', function () {
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('contacts.consents.store', $contact), ['scope' => 'marketing', 'source' => 'web_form', 'consented_at' => now()->toDateTimeString()])
        ->assertSessionHasErrors(['evidence' => 'Record how the opt-in was captured (form id, message, call note…).']);
});

it('rejects consent dated in the future', function () {
    $contact = Contact::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('contacts.consents.store', $contact), [
            'scope' => 'marketing', 'source' => 'web_form', 'evidence' => 'x', 'consented_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('consented_at');
});

it('revokes a consent record', function () {
    $consent = ContactConsent::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('contacts.consents.destroy', [$consent->contact, $consent]))
        ->assertRedirect();

    expect($consent->fresh()->revoked_at)->not->toBeNull();
});

it('returns 404 when revoking a consent through another contact', function () {
    $consent = ContactConsent::factory()->create();
    $otherContact = Contact::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('contacts.consents.destroy', [$otherContact, $consent]))
        ->assertNotFound();

    expect($consent->fresh()->revoked_at)->toBeNull();
});

it('revokes all active consent when the contact replies STOP', function (string $keyword) {
    $device = WhatsAppAccount::factory()->connected()->create();
    $contact = Contact::factory()->has(ContactConsent::factory()->count(2), 'consents')->create();

    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, $device->id, now(), [
        'externalId' => 'WA-STOP',
        'from' => ['jid' => $contact->wa_jid, 'phoneNumber' => $contact->phone_number],
        'type' => 'text',
        'body' => $keyword,
    ]));

    expect($contact->consents()->active()->count())->toBe(0);
})->with(['STOP', ' unsubscribe ', 'Stop all']);

it('keeps consent for messages that merely mention stopping', function () {
    $device = WhatsAppAccount::factory()->connected()->create();
    $contact = Contact::factory()->has(ContactConsent::factory(), 'consents')->create();

    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, $device->id, now(), [
        'externalId' => 'WA-NOT-STOP',
        'from' => ['jid' => $contact->wa_jid],
        'type' => 'text',
        'body' => 'Please stop by tomorrow',
    ]));

    expect($contact->consents()->active()->count())->toBe(1);
});
