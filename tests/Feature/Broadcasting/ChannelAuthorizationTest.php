<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // Channels register on the broadcaster active at boot (null in phpunit.xml).
    require base_path('routes/channels.php');
});

function authorizeChannel(string $channel): TestResponse
{
    return test()->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('rejects guests on every private channel', function (string $channel) {
    authorizeChannel($channel)->assertForbidden();
})->with(['private-devices', 'private-conversations', 'private-conversations.01JXXXXXXXXXXXXXXXXXXXXXXX']);

it('authorizes signed-in users on the workspace channels', function (string $channel) {
    $this->actingAs(User::factory()->create());

    authorizeChannel($channel)->assertOk()->assertJsonStructure(['auth']);
})->with(['private-devices', 'private-conversations']);

it('authorizes a conversation channel only for an existing conversation', function () {
    $this->actingAs(User::factory()->create());
    $conversation = Conversation::factory()->create();

    authorizeChannel("private-conversations.{$conversation->id}")->assertOk();
    authorizeChannel('private-conversations.01JDOESNOTEXIST0000000000')->assertForbidden();
});

it('refuses another user\'s private user channel', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $this->actingAs($user);

    authorizeChannel("private-App.Models.User.{$other->id}")->assertForbidden();
});
