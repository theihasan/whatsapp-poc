<?php

use App\Enums\DeviceStatus;
use App\Enums\GatewayEventStatus;
use App\Models\GatewayEvent;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'messaging.gateway.webhook_secret' => 'current-secret',
        'messaging.gateway.webhook_secret_previous' => null,
    ]);
});

/**
 * @param  array<string, mixed>  $event
 * @return array<string, mixed>
 */
function gatewayEvent(array $event = []): array
{
    return [
        'eventId' => (string) Str::ulid(),
        'type' => 'session.connecting',
        'schemaVersion' => 1,
        'sessionId' => (string) Str::ulid(),
        'occurredAt' => now()->toIso8601String(),
        'payload' => [],
        ...$event,
    ];
}

/**
 * @param  array<string, mixed>  $event
 */
function postSignedEvent(array $event, string $secret = 'current-secret', ?int $timestamp = null, ?string $rawBody = null): TestResponse
{
    $body = $rawBody ?? json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= time();

    return test()->call('POST', route('gateway.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GATEWAY_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_GATEWAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ], $body);
}

it('persists and processes a correctly signed event', function () {
    $device = WhatsAppAccount::factory()->create(['status' => DeviceStatus::Created]);
    $event = gatewayEvent(['sessionId' => $device->id]);

    postSignedEvent($event)->assertAccepted()->assertJson(['status' => 'accepted']);

    expect(GatewayEvent::query()->sole()->status)->toBe(GatewayEventStatus::Processed)
        ->and($device->fresh()->status)->toBe(DeviceStatus::Connecting);
});

it('returns 200 duplicate and does not reprocess a replayed event id', function () {
    $event = gatewayEvent();

    postSignedEvent($event)->assertAccepted();
    postSignedEvent($event)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(GatewayEvent::query()->count())->toBe(1);
});

it('returns 401 for a signature made with the wrong secret', function () {
    postSignedEvent(gatewayEvent(), 'attacker-secret')
        ->assertUnauthorized()
        ->assertJson(['error' => ['code' => 'invalid_signature']]);

    expect(GatewayEvent::query()->count())->toBe(0);
});

it('returns 401 when the body was altered after signing', function () {
    $event = gatewayEvent();
    $timestamp = time();
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.json_encode($event), 'current-secret');
    $tampered = json_encode([...$event, 'type' => 'session.logged_out']);

    $this->call('POST', route('gateway.events'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GATEWAY_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_GATEWAY_SIGNATURE' => $signature,
    ], $tampered)->assertUnauthorized();
});

it('returns 401 for a timestamp outside the tolerance window', function () {
    postSignedEvent(gatewayEvent(), timestamp: time() - 301)
        ->assertUnauthorized()
        ->assertJson(['error' => ['code' => 'invalid_timestamp']]);
});

it('accepts the previous secret during rotation', function () {
    config(['messaging.gateway.webhook_secret_previous' => 'old-secret']);

    postSignedEvent(gatewayEvent(), 'old-secret')->assertAccepted();
});

it('returns 413 for oversized payloads', function () {
    $event = gatewayEvent(['payload' => ['padding' => str_repeat('a', 1_100_000)]]);

    postSignedEvent($event)->assertStatus(413);
});

it('returns 422 for events that do not match the schema', function () {
    postSignedEvent(gatewayEvent(['schemaVersion' => 2, 'type' => 'DROP TABLE']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['schemaVersion', 'type']);
});

it('returns 503 when no webhook secret is configured', function () {
    config(['messaging.gateway.webhook_secret' => null]);

    postSignedEvent(gatewayEvent())->assertServiceUnavailable();
});

it('ignores events for unknown sessions without failing', function () {
    postSignedEvent(gatewayEvent(['type' => 'session.connected']))->assertAccepted();

    expect(GatewayEvent::query()->sole()->status)->toBe(GatewayEventStatus::Ignored);
});

it('ignores a stale QR event that arrives after the device connected', function () {
    $device = WhatsAppAccount::factory()->connected()->create();

    postSignedEvent(gatewayEvent([
        'sessionId' => $device->id,
        'type' => 'session.qr.updated',
        'payload' => ['qr' => 'stale', 'expiresInSeconds' => 20],
    ]))->assertAccepted();

    expect($device->fresh()->status)->toBe(DeviceStatus::Connected);
    $this->assertNull(cache()->get('device-qr:'.$device->id));
});
