<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->device = WhatsAppAccount::factory()->connected()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function groupMessage(WhatsAppAccount $device, array $overrides = []): void
{
    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, $device->id, now(), array_replace_recursive([
        'externalId' => 'G-'.Str::random(8),
        'chatType' => 'group',
        'from' => ['jid' => '120363000000000009@g.us', 'lid' => null, 'phoneNumber' => null, 'pushName' => 'Support crew'],
        'participant' => ['jid' => '15550100190@s.whatsapp.net', 'lid' => null, 'phoneNumber' => '+15550100190', 'pushName' => 'Rina'],
        'type' => 'text',
        'body' => 'Morning all',
    ], $overrides)));
}

it('stores a group as one conversation and records each sender on the message', function () {
    groupMessage($this->device);
    groupMessage($this->device, ['participant' => ['jid' => '15550100191@s.whatsapp.net', 'phoneNumber' => '+15550100191', 'pushName' => 'Omar'], 'body' => 'Hi']);

    $group = Contact::query()->sole();
    expect($group)->is_group->toBeTrue()->name->toBe('Support crew')->phone_number->toBeNull();
    expect(Conversation::query()->sole()->last_message_preview)->toBe('Omar: Hi');
    expect(Message::query()->orderBy('id')->pluck('sender_name')->all())->toBe(['Rina', 'Omar']);
});

it('does not turn group members into contacts', function () {
    groupMessage($this->device);

    expect(Contact::query()->where('wa_jid', '15550100190@s.whatsapp.net')->exists())->toBeFalse();
});

it('follows group renames', function () {
    groupMessage($this->device);
    groupMessage($this->device, ['from' => ['pushName' => 'Support crew (EU)']]);

    expect(Contact::query()->sole()->name)->toBe('Support crew (EU)');
});

it('never runs automations or STOP opt-out in groups', function () {
    AutomationRule::factory()->create(['conditions' => ['keywords' => ['stop']], 'actions' => [['type' => 'suggest_reply', 'text' => 'x']]]);
    groupMessage($this->device);
    $group = Contact::query()->sole();
    ContactConsent::factory()->for($group)->create();

    groupMessage($this->device, ['body' => 'STOP']);

    expect(AutomationExecution::query()->count())->toBe(0)
        ->and($group->consents()->active()->count())->toBe(1);
});

it('filters the inbox by direct chats and groups', function () {
    groupMessage($this->device);
    Conversation::factory()->for($this->device, 'whatsappAccount')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('inbox', ['filter' => 'groups']))
        ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.contact.is_group', true));
    $this->get(route('inbox', ['filter' => 'direct']))
        ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.contact.is_group', false));
});

it('exposes the group sender on messages and hides groups from the contacts list', function () {
    groupMessage($this->device);
    Contact::factory()->create(['name' => 'Real person']);
    $this->actingAs(User::factory()->create());

    $this->get(route('inbox.show', Conversation::query()->sole()))
        ->assertInertia(fn ($page) => $page->where('active.messages.0.group_sender', ['name' => 'Rina', 'phone_number' => '+15550100190']));
    $this->get(route('contacts.index'))
        ->assertInertia(fn ($page) => $page->has('contacts.data', 1)->where('contacts.data.0.name', 'Real person'));
});

it('replies to a group through the provider', function () {
    groupMessage($this->device);

    $this->actingAs(User::factory()->create())
        ->post(route('conversations.messages.store', Conversation::query()->sole()), ['body' => 'On it'])
        ->assertRedirect();

    expect(Message::query()->where('direction', 'outgoing')->sole()->status->value)->toBe('delivered');
});

it('simulates group messages on mock devices', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('devices.simulate.incoming', $this->device), ['body' => 'Hi team', 'name' => 'Sam', 'group_name' => 'Ops'])
        ->assertRedirect();

    expect(Contact::query()->where('is_group', true)->sole()->name)->toBe('Ops')
        ->and(Message::query()->sole()->sender_name)->toBe('Sam');
});
