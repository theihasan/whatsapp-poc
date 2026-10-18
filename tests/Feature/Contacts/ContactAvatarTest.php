<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Jobs\FetchContactAvatar;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\UnofficialWebProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['messaging.unofficial_enabled' => true, 'messaging.gateway.url' => 'http://gateway.test', 'messaging.gateway.token' => 't']);
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->device = WhatsAppAccount::factory()->unofficial()->connected()->create();
    $this->contact = Contact::factory()->create(['wa_jid' => '15550100180@s.whatsapp.net']);
});

function runAvatarJob(Contact $contact, WhatsAppAccount $device): void
{
    (new FetchContactAvatar($contact, $device))->handle(app(UnofficialWebProvider::class));
}

it('stores a visible profile picture privately', function () {
    Http::fake(['http://gateway.test/v1/sessions/*/contacts/15550100180@s.whatsapp.net/avatar' => Http::response("\xFF\xD8\xFFjpeg", 200, ['Content-Type' => 'image/jpeg'])]);

    runAvatarJob($this->contact, $this->device);

    $contact = $this->contact->fresh();
    expect($contact->avatar_path)->toBe("avatars/{$contact->id}.jpg")
        ->and($contact->avatar_checked_at)->not->toBeNull();
    Storage::disk('local')->assertExists($contact->avatar_path);
});

it('remembers a hidden picture and does not ask again within a week', function () {
    Http::fake(['http://gateway.test/*' => Http::response(['error' => ['code' => 'no_avatar']], 404)]);

    runAvatarJob($this->contact, $this->device);
    runAvatarJob($this->contact->fresh(), $this->device);

    expect($this->contact->fresh())->avatar_path->toBeNull()->avatar_checked_at->not->toBeNull();
    Http::assertSentCount(1);
});

it('serves the avatar only to signed-in users and 404s without one', function () {
    Storage::disk('local')->put('avatars/x.jpg', "\xFF\xD8\xFF");
    $this->contact->update(['avatar_path' => 'avatars/x.jpg', 'avatar_checked_at' => now()]);
    $withoutAvatar = Contact::factory()->create();

    $this->get(route('contacts.avatar', $this->contact))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('contacts.avatar', $this->contact))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(route('contacts.avatar', $withoutAvatar))->assertNotFound();
});

it('queues an avatar check for incoming WhatsApp Web messages only', function () {
    Queue::fake([FetchContactAvatar::class]);
    $mock = WhatsAppAccount::factory()->connected()->create();
    $payload = fn (string $id, string $jid) => ['externalId' => $id, 'from' => ['jid' => $jid], 'type' => 'text', 'body' => 'hi'];

    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, $this->device->id, now(), $payload('A', '15550100181@s.whatsapp.net')));
    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, $mock->id, now(), $payload('B', '15550100182@s.whatsapp.net')));

    Queue::assertPushed(FetchContactAvatar::class, 1);
    Queue::assertPushed(FetchContactAvatar::class, fn ($job) => $job->contact->wa_jid === '15550100181@s.whatsapp.net');
});

it('backfills only contacts with a conversation on a connected WhatsApp Web device', function () {
    Queue::fake([FetchContactAvatar::class]);
    Conversation::factory()->for($this->device, 'whatsappAccount')->for($this->contact)->create();
    Contact::factory()->create();

    $this->artisan('app:refresh-contact-avatars')->expectsOutput('Queued 1 avatar refresh(es).')->assertSuccessful();

    Queue::assertPushed(FetchContactAvatar::class, fn ($job) => $job->contact->is($this->contact));
});
