<?php

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Events\ConversationUpdated;
use App\Events\DeviceStatusChanged;
use App\Events\MessageCreated;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('announces a pending QR without broadcasting the code itself', function () {
    $device = WhatsAppAccount::factory()->create(['status' => 'connecting']);
    Event::fake([DeviceStatusChanged::class]);

    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'session.qr.updated', 1, $device->id, now(), [
        'qr' => 'SECRET-QR-PAYLOAD',
        'expiresInSeconds' => 20,
    ]));

    Event::assertDispatched(DeviceStatusChanged::class, function (DeviceStatusChanged $event) {
        $payload = $event->broadcastWith();

        return $payload['status'] === 'qr_ready'
            && ! str_contains(json_encode($payload), 'SECRET-QR-PAYLOAD')
            && $event->broadcastOn()[0]->name === 'private-devices';
    });
});

it('broadcasts incoming messages as resources on private channels only', function () {
    $device = WhatsAppAccount::factory()->connected()->create();
    Event::fake([MessageCreated::class, ConversationUpdated::class]);

    app(RecordGatewayEvent::class)->handle(new GatewayEventData((string) Str::ulid(), 'message.received', 1, $device->id, now(), [
        'externalId' => 'WA-1',
        'from' => ['jid' => '15550100190@s.whatsapp.net', 'phoneNumber' => '+15550100190', 'pushName' => 'Sam'],
        'type' => 'text',
        'body' => 'Hi',
        'rawProtocolField' => 'must not leak',
    ]));

    Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) {
        $payload = $event->broadcastWith()['message'];

        return str_starts_with($event->broadcastOn()[0]->name, 'private-conversations.')
            && $payload['body'] === 'Hi'
            && ! array_key_exists('external_id', $payload)
            && ! str_contains(json_encode($payload), 'must not leak');
    });
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) => $event->broadcastOn()[0]->name === 'private-conversations'
        && $event->broadcastWith()['conversation']['unread_count'] === 1);
});
