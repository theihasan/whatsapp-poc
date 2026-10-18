<?php

use App\Models\Contact;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('fills phone numbers for LID-only contacts from connected WhatsApp Web devices', function () {
    config(['messaging.unofficial_enabled' => true, 'messaging.gateway.url' => 'http://gateway.test', 'messaging.gateway.token' => 't']);
    Http::preventStrayRequests();
    $device = WhatsAppAccount::factory()->unofficial()->connected()->create();
    $lidOnly = Contact::factory()->create(['wa_jid' => '123456789012345@lid', 'phone_number' => null]);
    $known = Contact::factory()->create();
    Http::fake(["http://gateway.test/v1/sessions/{$device->id}/contacts/resolve" => Http::response(['identities' => [
        ['lid' => '123456789012345@lid', 'jid' => '15550100170@s.whatsapp.net', 'phoneNumber' => '+15550100170'],
    ]])]);

    $this->artisan('app:resolve-contact-numbers')->expectsOutput('Resolved 1 contact(s).')->assertSuccessful();

    expect($lidOnly->fresh())->phone_number->toBe('+15550100170')->wa_lid->toBe('123456789012345@lid');
    Http::assertSent(fn (Request $request) => $request['lids'] === ['123456789012345@lid']);
    expect($known->fresh()->phone_number)->not->toBeNull();
});
