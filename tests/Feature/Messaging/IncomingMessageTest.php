<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Enums\ContactSource;
use App\Enums\ConversationStatus;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\NewConversationStarted;
use App\Services\WorkspaceSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $payload
 */
function receiveEvent(WhatsAppAccount $account, string $type, array $payload): void
{
    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), $type, 1, $account->id, now(), $payload));
}

/**
 * @return array<string, mixed>
 */
function incomingPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'externalId' => 'WA-IN-1',
        'from' => ['jid' => '15550100150@s.whatsapp.net', 'phoneNumber' => '+15550100150', 'pushName' => 'Jamie'],
        'type' => 'text',
        'body' => 'Do you open on Sundays?',
        'timestamp' => now()->toIso8601String(),
    ], $overrides);
}

beforeEach(function () {
    $this->account = WhatsAppAccount::factory()->connected()->create();
});

it('creates the contact, conversation and message for a new sender', function () {
    receiveEvent($this->account, 'message.received', incomingPayload());

    $contact = Contact::query()->sole();
    $conversation = Conversation::query()->sole();
    expect($contact)
        ->wa_jid->toBe('15550100150@s.whatsapp.net')
        ->phone_number->toBe('+15550100150')
        ->name->toBe('Jamie')
        ->source->toBe(ContactSource::IncomingMessage);
    expect($conversation)
        ->unread_count->toBe(1)
        ->last_message_preview->toBe('Do you open on Sundays?');
    expect(Message::query()->sole())
        ->status->toBe(MessageStatus::Received)
        ->body->toBe('Do you open on Sundays?');
});

it('does not create a duplicate when the same message is delivered twice', function () {
    receiveEvent($this->account, 'message.received', incomingPayload());
    receiveEvent($this->account, 'message.received', incomingPayload());

    expect(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->sole()->unread_count)->toBe(1);
});

it('does not invent a phone number when only a WhatsApp identifier is known', function () {
    receiveEvent($this->account, 'message.received', incomingPayload([
        'from' => ['jid' => '123456789012345@lid', 'phoneNumber' => null],
    ]));

    expect(Contact::query()->sole()->phone_number)->toBeNull();
});

it('stores unsupported message types instead of dropping them', function () {
    receiveEvent($this->account, 'message.received', incomingPayload(['type' => 'locationMessage', 'body' => null]));

    expect(Message::query()->sole()->type->value)->toBe('unsupported')
        ->and(Conversation::query()->sole()->last_message_preview)->toBe('Unsupported message');
});

it('reopens a closed conversation when the contact writes again', function () {
    receiveEvent($this->account, 'message.received', incomingPayload());
    Conversation::query()->update(['status' => ConversationStatus::Closed]);

    receiveEvent($this->account, 'message.received', incomingPayload(['externalId' => 'WA-IN-2']));

    expect(Conversation::query()->sole()->status)->toBe(ConversationStatus::Open);
});

it('ignores delivery receipts that arrive out of order', function () {
    $conversation = Conversation::factory()->for($this->account, 'whatsappAccount')->create();
    $message = Message::factory()->for($conversation)->outgoing(MessageStatus::GatewayAccepted)->create(['external_id' => 'WA-OUT-1']);

    receiveEvent($this->account, 'message.status.updated', ['externalId' => 'WA-OUT-1', 'status' => 'read']);
    receiveEvent($this->account, 'message.status.updated', ['externalId' => 'WA-OUT-1', 'status' => 'delivered']);
    receiveEvent($this->account, 'message.failed', ['externalId' => 'WA-OUT-1', 'reason' => 'late failure']);

    expect($message->fresh())
        ->status->toBe(MessageStatus::Read)
        ->read_at->not->toBeNull()
        ->delivered_at->not->toBeNull();
});

it('matches a receipt that arrives before the send result by idempotency key', function () {
    $conversation = Conversation::factory()->for($this->account, 'whatsappAccount')->create();
    $message = Message::factory()->for($conversation)->outgoing(MessageStatus::Processing)->create();

    receiveEvent($this->account, 'message.status.updated', [
        'externalId' => 'WA-OUT-2',
        'idempotencyKey' => $message->idempotency_key,
        'status' => 'sent',
    ]);

    expect($message->fresh())
        ->status->toBe(MessageStatus::Sent)
        ->external_id->toBe('WA-OUT-2');
});

it('ignores receipts for messages of another device', function () {
    $other = Conversation::factory()->create();
    $message = Message::factory()->for($other)->outgoing(MessageStatus::GatewayAccepted)->create(['external_id' => 'WA-OUT-3']);

    receiveEvent($this->account, 'message.status.updated', ['externalId' => 'WA-OUT-3', 'status' => 'read']);

    expect($message->fresh()->status)->toBe(MessageStatus::GatewayAccepted);
});

it('notifies the team about a new conversation only when enabled', function (bool $enabled, int $expected) {
    Notification::fake();
    app(WorkspaceSettings::class)->update(['notify_on_new_conversation' => $enabled]);
    User::factory()->count(2)->create();

    receiveEvent($this->account, 'message.received', incomingPayload());
    receiveEvent($this->account, 'message.received', incomingPayload(['externalId' => 'WA-IN-2']));

    Notification::assertSentTimes(NewConversationStarted::class, $expected);
})->with(['enabled' => [true, 2], 'disabled' => [false, 0]]);
