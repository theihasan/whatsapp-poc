<?php

use App\Enums\MessageStatus;
use App\Enums\MessagingProviderName;
use App\Jobs\SendOutgoingMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\MessageDeliveryFailed;
use App\Services\Messaging\MessagingProviderRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->conversation = Conversation::factory()
        ->for(WhatsAppAccount::factory()->connected(), 'whatsappAccount')
        ->create(['unread_count' => 3]);
});

it('persists the message as queued before dispatching delivery', function () {
    Queue::fake([SendOutgoingMessage::class]);

    $this->actingAs($this->user)
        ->post(route('conversations.messages.store', $this->conversation), ['body' => 'Hello there'])
        ->assertRedirect();

    $message = Message::query()->sole();
    expect($message->status)->toBe(MessageStatus::Queued)
        ->and($message->idempotency_key)->not->toBeNull()
        ->and($message->user_id)->toBe($this->user->id);
    expect($this->conversation->fresh())
        ->last_message_preview->toBe('Hello there')
        ->unread_count->toBe(0);
    Queue::assertPushed(SendOutgoingMessage::class, fn ($job) => $job->message->is($message));
});

it('delivers through the mock provider and records the external id', function () {
    $this->actingAs($this->user)->post(route('conversations.messages.store', $this->conversation), ['body' => 'Hello']);

    $message = Message::query()->sole();
    expect($message->status)->toBe(MessageStatus::Delivered)
        ->and($message->external_id)->toStartWith('MOCK-OUT-')
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->delivered_at)->not->toBeNull();
});

it('marks rejected sends as failed with the reason', function () {
    $this->actingAs($this->user)->post(route('conversations.messages.store', $this->conversation), ['body' => 'please #fail']);

    expect(Message::query()->sole())
        ->status->toBe(MessageStatus::Failed)
        ->failure_reason->toBe('Simulated rejection by the mock provider.');
});

it('marks sends with an unconfirmed outcome as unknown instead of resending', function () {
    $this->actingAs($this->user)->post(route('conversations.messages.store', $this->conversation), ['body' => 'slow #timeout']);

    expect(Message::query()->sole())
        ->status->toBe(MessageStatus::Unknown)
        ->external_id->toBeNull();
});

it('retries an unknown message with the same idempotency key', function () {
    $message = Message::factory()->for($this->conversation)->outgoing(MessageStatus::Unknown)->create(['body' => 'Hi again']);
    $key = $message->idempotency_key;

    $this->actingAs($this->user)->post(route('messages.retry', $message))->assertRedirect();

    expect($message->fresh())
        ->status->toBe(MessageStatus::Delivered)
        ->idempotency_key->toBe($key);
});

it('refuses to retry a message that was already delivered', function () {
    $message = Message::factory()->for($this->conversation)->outgoing(MessageStatus::Delivered)->create();

    $this->actingAs($this->user)
        ->post(route('messages.retry', $message))
        ->assertInertiaFlash('toast.message', 'Only failed or unconfirmed messages can be retried.');
});

it('keeps the message queued and retries later while the gateway is unreachable', function () {
    config(['messaging.unofficial_enabled' => true, 'messaging.gateway.url' => 'http://gateway.test']);
    $conversation = Conversation::factory()->for(WhatsAppAccount::factory()->unofficial()->connected(), 'whatsappAccount')->create();
    $message = Message::factory()->for($conversation)->outgoing()->create();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to gateway.test port 80: Connection refused'));

    $job = (new SendOutgoingMessage($message))->withFakeQueueInteractions();
    $job->handle(app(MessagingProviderRegistry::class));

    $job->assertReleased();
    expect($message->fresh()->status)->toBe(MessageStatus::Queued)
        ->and($message->fresh()->failure_reason)->toStartWith('Waiting to retry:');
});

it('marks the message failed once delivery attempts are exhausted', function () {
    $message = Message::factory()->for($this->conversation)->outgoing()->create();

    (new SendOutgoingMessage($message))->failed(new RuntimeException('gateway down'));

    expect($message->fresh())
        ->status->toBe(MessageStatus::Failed)
        ->failure_reason->toBe('Delivery gave up: gateway down');
});

it('does not resend an interrupted attempt through a provider without idempotent sends', function () {
    config(['messaging.official.access_token' => 'token', 'messaging.official.phone_number_id' => '123']);
    Http::preventStrayRequests();
    $account = WhatsAppAccount::factory()->connected()->create(['provider' => MessagingProviderName::OfficialCloud]);
    $conversation = Conversation::factory()->for($account, 'whatsappAccount')->create();
    $message = Message::factory()->for($conversation)->outgoing(MessageStatus::Processing)->create();

    (new SendOutgoingMessage($message))->handle(app(MessagingProviderRegistry::class));

    expect($message->fresh())
        ->status->toBe(MessageStatus::Unknown)
        ->failure_reason->toBe('A previous attempt was interrupted; not resent to avoid a duplicate.');
});

it('requires text or an attachment', function () {
    $this->actingAs($this->user)
        ->post(route('conversations.messages.store', $this->conversation), ['body' => ''])
        ->assertSessionHasErrors(['body' => 'Write a message or attach a file.']);
});

it('rejects attachment types that are not allowed', function () {
    $this->actingAs($this->user)
        ->post(route('conversations.messages.store', $this->conversation), [
            'attachment' => UploadedFile::fake()->create('script.svg', 4, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors(['attachment' => 'Only images (JPG, PNG, WebP) and documents (PDF, Word, Excel, text) can be sent.']);
});

it('stores attachments privately and serves them only to signed-in users', function () {
    Storage::fake('local');

    $this->actingAs($this->user)->post(route('conversations.messages.store', $this->conversation), [
        'body' => 'See attached',
        'attachment' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'),
    ]);

    $attachment = Message::query()->sole()->attachments()->sole();
    Storage::disk('local')->assertExists($attachment->path);
    expect(Message::query()->sole()->type->value)->toBe('document');

    $this->get(route('attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('quote.pdf');

    auth()->logout();
    $this->get(route('attachments.show', $attachment))->assertRedirect(route('login'));
});

it('notifies the sender when a message fails', function () {
    Notification::fake();

    $this->actingAs($this->user)->post(route('conversations.messages.store', $this->conversation), ['body' => 'boom #fail']);

    Notification::assertSentTo($this->user, MessageDeliveryFailed::class);
});
