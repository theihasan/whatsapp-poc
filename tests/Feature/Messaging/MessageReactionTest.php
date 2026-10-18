<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\GatewayEvent;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->device = WhatsAppAccount::factory()->connected()->create();
    $this->conversation = Conversation::factory()->for($this->device, 'whatsappAccount')->create(['unread_count' => 0, 'last_message_preview' => 'automated reply']);
    $this->message = Message::factory()->for($this->conversation)->outgoing(MessageStatus::Read)->create(['external_id' => 'WA-OUT-R1']);
});

function react(WhatsAppAccount $device, string $emoji, string $reactor = '15550100195@s.whatsapp.net', string $name = 'Tanzim', string $target = 'WA-OUT-R1'): void
{
    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.reaction', 1, $device->id, now(), [
        'externalId' => 'RX-'.Str::random(6),
        'targetExternalId' => $target,
        'emoji' => $emoji,
        'chatType' => 'direct',
        'from' => ['jid' => $reactor, 'lid' => null, 'phoneNumber' => null, 'pushName' => $name],
        'participant' => null,
    ]));
}

it('attaches a reaction to the target message without creating a message or unread', function () {
    react($this->device, '💙');

    expect(Message::query()->count())->toBe(1)
        ->and($this->message->fresh()->reactions)->toHaveCount(1)
        ->and($this->message->fresh()->reactions[0])->toMatchArray(['emoji' => '💙', 'name' => 'Tanzim']);
    expect($this->conversation->fresh())
        ->unread_count->toBe(0)
        ->last_message_preview->toBe('automated reply');
});

it('replaces a person\'s reaction and removes it when cleared', function () {
    react($this->device, '💙');
    react($this->device, '😂');
    expect($this->message->fresh()->reactions)->toHaveCount(1)
        ->and($this->message->fresh()->reactions[0]['emoji'])->toBe('😂');

    react($this->device, '');
    expect($this->message->fresh()->reactions)->toBeNull();
});

it('keeps one reaction per person', function () {
    react($this->device, '👍');
    react($this->device, '👍', '15550100196@s.whatsapp.net', 'Omar');

    $this->actingAs(User::factory()->create())
        ->get(route('inbox.show', $this->conversation))
        ->assertInertia(fn ($page) => $page->where('active.messages.0.reactions', [
            ['emoji' => '👍', 'name' => 'Tanzim'],
            ['emoji' => '👍', 'name' => 'Omar'],
        ]));
});

it('ignores reactions to messages it does not know', function () {
    react($this->device, '🔥', target: 'UNKNOWN');

    expect(GatewayEvent::query()->sole()->status->value)->toBe('ignored');
});
