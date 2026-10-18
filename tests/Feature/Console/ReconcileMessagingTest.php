<?php

use App\Enums\GatewayEventStatus;
use App\Enums\MessageStatus;
use App\Jobs\ProcessGatewayEvent;
use App\Jobs\SendOutgoingMessage;
use App\Models\GatewayEvent;
use App\Models\Message;
use Illuminate\Support\Facades\Queue;

it('re-dispatches stale pending events and queued messages', function () {
    $staleEvent = GatewayEvent::factory()->create(['created_at' => now()->subMinutes(11)]);
    GatewayEvent::factory()->create();
    $staleMessage = Message::factory()->outgoing(MessageStatus::Queued)->create(['updated_at' => now()->subMinutes(31)]);
    Message::factory()->outgoing(MessageStatus::Queued)->create();
    Queue::fake([ProcessGatewayEvent::class, SendOutgoingMessage::class]);

    $this->artisan('app:reconcile-messaging')->assertSuccessful();

    Queue::assertPushed(ProcessGatewayEvent::class, 1);
    Queue::assertPushed(ProcessGatewayEvent::class, fn ($job) => $job->event->is($staleEvent));
    Queue::assertPushed(SendOutgoingMessage::class, 1);
    Queue::assertPushed(SendOutgoingMessage::class, fn ($job) => $job->message->is($staleMessage));
});

it('flags sends interrupted mid-flight as unknown instead of resending', function () {
    $message = Message::factory()->outgoing(MessageStatus::Processing)->create(['updated_at' => now()->subMinutes(16)]);
    Queue::fake([SendOutgoingMessage::class]);

    $this->artisan('app:reconcile-messaging')->assertSuccessful();

    expect($message->fresh()->status)->toBe(MessageStatus::Unknown);
    Queue::assertNothingPushed();
});

it('prunes processed gateway events past retention but keeps failed ones', function () {
    $old = GatewayEvent::factory()->create(['status' => GatewayEventStatus::Processed, 'created_at' => now()->subDays(31)]);
    $failed = GatewayEvent::factory()->create(['status' => GatewayEventStatus::Failed, 'created_at' => now()->subDays(31)]);
    $recent = GatewayEvent::factory()->create(['status' => GatewayEventStatus::Processed]);

    $this->artisan('app:prune-records')->assertSuccessful();

    $this->assertModelMissing($old);
    $this->assertModelExists($failed);
    $this->assertModelExists($recent);
});
