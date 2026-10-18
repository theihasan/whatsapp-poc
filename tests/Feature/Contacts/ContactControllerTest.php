<?php

use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\Tag;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

describe('index', function () {
    it('filters contacts by consent', function () {
        $optedIn = Contact::factory()->has(ContactConsent::factory(), 'consents')->create(['name' => 'Opted']);
        Contact::factory()->has(ContactConsent::factory()->revoked(), 'consents')->create(['name' => 'Revoked']);
        Contact::factory()->create(['name' => 'Never']);

        $this->get(route('contacts.index', ['consent' => 'opted_in']))
            ->assertInertia(fn ($page) => $page->component('contacts/index')->has('contacts.data', 1)->where('contacts.data.0.id', $optedIn->id)->where('contacts.data.0.has_consent', true));
        $this->get(route('contacts.index', ['consent' => 'none']))
            ->assertInertia(fn ($page) => $page->has('contacts.data', 2));
    });

    it('filters contacts by tag', function () {
        $vip = Tag::factory()->create(['name' => 'vip']);
        Contact::factory()->hasAttached($vip)->create(['name' => 'Tagged']);
        Contact::factory()->create();

        $this->get(route('contacts.index', ['tag' => 'vip']))
            ->assertInertia(fn ($page) => $page->has('contacts.data', 1)->where('contacts.data.0.name', 'Tagged'));
    });
});

describe('store', function () {
    it('normalizes the number, derives the WhatsApp id and records no consent', function () {
        $this->post(route('contacts.store'), [
            'name' => 'Rita Gomez',
            'phone_number' => '+1 (202) 555-0142',
            'tags' => ['VIP', 'lead'],
        ])->assertRedirect();

        $contact = Contact::query()->sole();
        expect($contact)
            ->phone_number->toBe('+12025550142')
            ->wa_jid->toBe('12025550142@s.whatsapp.net')
            ->source->toBe(ContactSource::Manual);
        expect($contact->tags->pluck('name')->sort()->values()->all())->toBe(['lead', 'vip']);
        expect($contact->consents()->count())->toBe(0);
    });

    it('rejects numbers without a country code', function () {
        $this->post(route('contacts.store'), ['name' => 'Local', 'phone_number' => '555 0142'])
            ->assertSessionHasErrors(['phone_number' => 'Enter the number in international format, e.g. +1 202 555 0123.']);
    });

    it('rejects a duplicate number in a different format', function () {
        Contact::factory()->create(['phone_number' => '+12025550142', 'wa_jid' => '12025550142@s.whatsapp.net']);

        $this->post(route('contacts.store'), ['name' => 'Dup', 'phone_number' => '0012025550142'])
            ->assertSessionHasErrors(['phone_number' => 'A contact with this number already exists.']);

        expect(Contact::query()->count())->toBe(1);
    });
});

describe('update', function () {
    it('updates notes and replaces tags without changing the number', function () {
        $contact = Contact::factory()->hasAttached(Tag::factory()->state(['name' => 'old']))->create();

        $this->patch(route('contacts.update', $contact), ['name' => 'New name', 'notes' => 'Prefers mornings', 'tags' => ['billing']])
            ->assertRedirect();

        expect($contact->fresh())
            ->name->toBe('New name')
            ->notes->toBe('Prefers mornings');
        expect($contact->fresh()->tags->pluck('name')->all())->toBe(['billing']);
    });
});

describe('show', function () {
    it('renders consent history, conversations and activity', function () {
        $contact = Contact::factory()->has(ContactConsent::factory(), 'consents')->create();

        $this->get(route('contacts.show', $contact))
            ->assertInertia(fn ($page) => $page
                ->component('contacts/show')
                ->has('contact.consents', 1)
                ->has('conversations')
                ->has('activity.events'));
    });
});

describe('destroy', function () {
    it('deletes the contact', function () {
        $contact = Contact::factory()->create();

        $this->delete(route('contacts.destroy', $contact))->assertRedirect(route('contacts.index'));

        $this->assertModelMissing($contact);
    });
});
