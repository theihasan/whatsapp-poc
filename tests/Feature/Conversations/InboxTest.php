<?php

use App\Enums\ConversationStatus;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('redirects guests to the login page', function () {
    $this->get(route('inbox'))->assertRedirect(route('login'));
});

it('filters conversations', function (string $filter, string $expectedName) {
    $agent = $this->user;
    Conversation::factory()->for(Contact::factory()->state(['name' => 'Unread person']))->create(['unread_count' => 2]);
    Conversation::factory()->for(Contact::factory()->state(['name' => 'Closed person']))->create(['status' => ConversationStatus::Closed]);
    Conversation::factory()->for(Contact::factory()->state(['name' => 'My person']))->create(['assigned_user_id' => $agent->id]);

    $this->actingAs($agent)
        ->get(route('inbox', ['filter' => $filter]))
        ->assertInertia(fn ($page) => $page
            ->component('inbox/index')
            ->has('conversations', 1)
            ->where('conversations.0.contact.name', $expectedName));
})->with([
    'unread' => ['unread', 'Unread person'],
    'closed' => ['closed', 'Closed person'],
    'mine' => ['mine', 'My person'],
]);

it('searches conversations by contact name or number', function () {
    Conversation::factory()->for(Contact::factory()->state(['name' => 'Priya Shah']))->create();
    Conversation::factory()->for(Contact::factory()->state(['name' => 'Someone else']))->create();

    $this->actingAs($this->user)
        ->get(route('inbox', ['search' => 'priya']))
        ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.contact.name', 'Priya Shah'));
});

it('treats search wildcards literally', function () {
    Conversation::factory()->for(Contact::factory()->state(['name' => 'Alex']))->create();

    $this->actingAs($this->user)
        ->get(route('inbox', ['search' => '%']))
        ->assertInertia(fn ($page) => $page->has('conversations', 0));
});

it('opens a conversation with its latest messages and marks it read', function () {
    $conversation = Conversation::factory()->create(['unread_count' => 4]);
    Message::factory()->for($conversation)->count(3)->create();

    $this->actingAs($this->user)
        ->get(route('inbox.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->where('active.conversation.id', $conversation->id)
            ->has('active.messages', 3)
            ->where('active.has_older', false));

    expect($conversation->fresh()->unread_count)->toBe(0);
});

it('paginates older messages with a cursor', function () {
    $conversation = Conversation::factory()->create();
    $messages = collect(range(1, 55))->map(function (int $i) use ($conversation) {
        $this->travel(1)->seconds();

        return Message::factory()->for($conversation)->create(['body' => "Message {$i}"]);
    });

    $this->actingAs($this->user)
        ->get(route('inbox.show', $conversation))
        ->assertInertia(fn ($page) => $page->has('active.messages', 50)->where('active.has_older', true)->where('active.messages.0.body', 'Message 6'));

    $this->getJson(route('conversations.messages.index', [$conversation, 'before' => $messages[5]->id]))
        ->assertOk()
        ->assertJsonCount(5, 'messages')
        ->assertJsonPath('messages.0.body', 'Message 1')
        ->assertJsonPath('has_older', false);
});

it('assigns a conversation and records it in the audit log', function () {
    $conversation = Conversation::factory()->create();
    $agent = User::factory()->create();

    $this->actingAs($this->user)
        ->patch(route('conversations.update', $conversation), ['assigned_user_id' => $agent->id, 'status' => 'closed'])
        ->assertRedirect();

    expect($conversation->fresh())
        ->assigned_user_id->toBe($agent->id)
        ->status->toBe(ConversationStatus::Closed);
    expect(AuditLog::query()->pluck('action')->all())->toContain('conversation.assigned', 'conversation.status_changed');
});

it('rejects assignment to a user that does not exist', function () {
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->user)
        ->patch(route('conversations.update', $conversation), ['assigned_user_id' => 999])
        ->assertSessionHasErrors('assigned_user_id');
});

it('starts a conversation with an existing contact on a device', function () {
    $contact = Contact::factory()->create();
    $device = WhatsAppAccount::factory()->connected()->create();

    $this->actingAs($this->user)
        ->post(route('conversations.store'), ['contact_id' => $contact->id, 'whatsapp_account_id' => $device->id])
        ->assertRedirect(route('inbox.show', Conversation::query()->sole()));
});
