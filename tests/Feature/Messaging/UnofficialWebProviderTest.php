<?php

use App\DTOs\OutgoingMessage;
use App\Enums\MessageType;
use App\Enums\SendOutcome;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\UnofficialWebProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'messaging.gateway.url' => 'http://gateway.test',
        'messaging.gateway.token' => 'internal-token',
    ]);
    Http::preventStrayRequests();
});

function outgoing(): OutgoingMessage
{
    return new OutgoingMessage('01JTESTIDEMPOTENCYKEY00000', '15550100100@s.whatsapp.net', MessageType::Text, 'Hello');
}

it('starts a session with the bearer token and a correlation id', function () {
    $device = WhatsAppAccount::factory()->unofficial()->create();
    Http::fake(['http://gateway.test/v1/sessions' => Http::response(['session' => ['id' => $device->id, 'status' => 'connecting']], 202)]);

    app(UnofficialWebProvider::class)->connect($device);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer internal-token')
        && $request->hasHeader('X-Correlation-Id')
        && $request['sessionId'] === $device->id);
});

it('maps gateway send responses to outcomes', function (int $status, array $body, SendOutcome $expected) {
    $device = WhatsAppAccount::factory()->unofficial()->create();
    Http::fake(["http://gateway.test/v1/sessions/{$device->id}/messages" => Http::response($body, $status)]);

    $result = app(UnofficialWebProvider::class)->sendMessage($device, outgoing());

    expect($result->outcome)->toBe($expected);
})->with([
    'accepted' => [200, ['messageId' => 'ABC123', 'status' => 'accepted'], SendOutcome::Accepted],
    'invalid recipient' => [422, ['error' => ['code' => 'invalid_recipient', 'message' => 'Not on WhatsApp']], SendOutcome::Rejected],
    'gateway error' => [500, ['error' => ['code' => 'internal']], SendOutcome::Unknown],
    'send timeout' => [504, ['error' => ['code' => 'send_timeout']], SendOutcome::Unknown],
]);

it('treats a timeout after dispatch as an unknown outcome', function () {
    $device = WhatsAppAccount::factory()->unofficial()->create();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15000 milliseconds'));

    $result = app(UnofficialWebProvider::class)->sendMessage($device, outgoing());

    expect($result->outcome)->toBe(SendOutcome::Unknown);
});

it('throws a retryable exception when the gateway refuses the connection', function () {
    $device = WhatsAppAccount::factory()->unofficial()->create();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to gateway.test port 80: Connection refused'));

    app(UnofficialWebProvider::class)->sendMessage($device, outgoing());
})->throws(ProviderUnavailableException::class);

it('throws a retryable exception when the session is not connected', function () {
    $device = WhatsAppAccount::factory()->unofficial()->create();
    Http::fake(["http://gateway.test/v1/sessions/{$device->id}/messages" => Http::response(['error' => ['code' => 'session_not_connected']], 409)]);

    app(UnofficialWebProvider::class)->sendMessage($device, outgoing());
})->throws(ProviderUnavailableException::class);

it('sends lifecycle commands with a JSON object body the gateway accepts', function (string $method) {
    $device = WhatsAppAccount::factory()->unofficial()->create();
    Http::fake(["http://gateway.test/v1/sessions/{$device->id}*" => Http::response(['revoked' => true], 202)]);

    app(UnofficialWebProvider::class)->{$method}($device);

    Http::assertSent(fn (Request $request) => $request->body() === '{}'
        && $request->hasHeader('Content-Type', 'application/json'));
})->with(['disconnect', 'revoke']);
