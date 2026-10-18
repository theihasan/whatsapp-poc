<?php

use App\Enums\MessageStatus;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Notifications\AutomationTriggered;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

it('reports accurate counts for the last seven days and labels simulated data', function () {
    $this->freezeTime();
    $agent = User::factory()->create(['name' => 'Agent A']);
    $device = WhatsAppAccount::factory()->connected()->create();
    WhatsAppAccount::factory()->create();
    $conversation = Conversation::factory()->for($device, 'whatsappAccount')->create(['unread_count' => 2, 'assigned_user_id' => $agent->id]);
    Message::factory()->for($conversation)->count(3)->create();
    Message::factory()->for($conversation)->outgoing(MessageStatus::Failed)->create();
    Message::factory()->for($conversation)->outgoing(MessageStatus::Delivered)->create(['created_at' => now()->subDays(10)]);

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('metrics.simulated', true)
            ->where('metrics.devices', ['connected' => 1, 'total' => 2])
            ->where('metrics.conversations.unread', 1)
            ->where('metrics.messages_7d.incoming', 3)
            ->where('metrics.messages_7d.outgoing', 1)
            ->where('metrics.messages_7d.failed', 1)
            ->where('metrics.workload', [['name' => 'Agent A', 'open' => 1]])
            ->has('metrics.activity', 14)
            ->missing('health'));
});

it('does not expose message content on the dashboard', function () {
    $conversation = Conversation::factory()->create(['last_message_preview' => 'secret preview']);
    Message::factory()->for($conversation)->create(['body' => 'TOP-SECRET-BODY']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee('TOP-SECRET-BODY')
        ->assertDontSee('secret preview');
});

it('loads system health as a deferred prop', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->has('health')->where('health.0.name', 'Database')->where('health.0.healthy', true)));
});

it('marks notifications as read', function () {
    $user = User::factory()->create();
    $user->notify(new AutomationTriggered(AutomationRule::factory()->create(), Conversation::factory()->create()));

    $this->actingAs($user)->post(route('notifications.read'))->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0);
});
