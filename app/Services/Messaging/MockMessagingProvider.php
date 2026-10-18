<?php

namespace App\Services\Messaging;

use App\Actions\Gateway\RecordGatewayEvent;
use App\Contracts\MessagingProvider;
use App\DTOs\GatewayEventData;
use App\DTOs\OutgoingMessage;
use App\DTOs\ProviderCapabilities;
use App\DTOs\ProviderHealth;
use App\DTOs\SendResult;
use App\Enums\MessagingProviderName;
use App\Models\WhatsAppAccount;
use App\Support\FakePhone;
use Illuminate\Support\Str;

/**
 * Deterministic in-process provider. It emits the same normalized events the real gateway
 * would, so the full Laravel pipeline runs without Node or WhatsApp.
 *
 * Magic message bodies for demos/tests: containing "#fail" is rejected, "#timeout" yields
 * an unknown outcome.
 */
class MockMessagingProvider implements MessagingProvider
{
    public function __construct(private RecordGatewayEvent $recordGatewayEvent) {}

    public function name(): MessagingProviderName
    {
        return MessagingProviderName::Mock;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            usesQrPairing: true,
            supportsMedia: true,
            supportsAutoReply: true,
            supportsBulkMessaging: true,
            isSimulated: true,
            idempotentSends: true,
        );
    }

    public function connect(WhatsAppAccount $account): void
    {
        $this->emit($account, 'session.qr.updated', [
            'qr' => 'MOCK-QR:'.Str::ulid(),
            'expiresInSeconds' => (int) config('messaging.qr_ttl'),
        ]);
    }

    /**
     * Simulates the user scanning the QR code with their phone.
     */
    public function simulateScan(WhatsAppAccount $account): void
    {
        $phoneNumber = FakePhone::number();

        $this->emit($account, 'session.connected', [
            'phoneNumber' => $phoneNumber,
            'jid' => FakePhone::jid($phoneNumber),
            'displayName' => 'Mock device',
        ]);
    }

    public function simulateIncomingMessage(WhatsAppAccount $account, string $fromPhoneNumber, ?string $name, string $body, ?string $groupName = null): void
    {
        $sender = [
            'jid' => FakePhone::jid($fromPhoneNumber),
            'lid' => null,
            'phoneNumber' => $fromPhoneNumber,
            'pushName' => $name,
        ];

        $this->emit($account, 'message.received', [
            'externalId' => 'MOCK-IN-'.Str::ulid(),
            'chatType' => $groupName === null ? 'direct' : 'group',
            'from' => $groupName === null ? $sender : [
                'jid' => '120363'.substr(sprintf('%u', crc32(mb_strtolower($groupName))).'000000000000', 0, 12).'@g.us',
                'lid' => null,
                'phoneNumber' => null,
                'pushName' => $groupName,
            ],
            'participant' => $groupName === null ? null : $sender,
            'type' => 'text',
            'body' => $body,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function disconnect(WhatsAppAccount $account): void
    {
        $this->emit($account, 'session.disconnected', ['reason' => 'manual']);
    }

    public function revoke(WhatsAppAccount $account): void
    {
        $this->emit($account, 'session.logged_out', ['reason' => 'revoked']);
    }

    public function sendMessage(WhatsAppAccount $account, OutgoingMessage $message): SendResult
    {
        if (Str::contains((string) $message->body, '#fail')) {
            return SendResult::rejected('Simulated rejection by the mock provider.');
        }

        if (Str::contains((string) $message->body, '#timeout')) {
            return SendResult::unknown('Simulated timeout: the mock provider did not confirm the send.');
        }

        $externalId = 'MOCK-OUT-'.Str::upper(substr(hash('sha256', $message->idempotencyKey), 0, 20));

        foreach (['sent', 'delivered'] as $status) {
            $this->emit($account, 'message.status.updated', [
                'externalId' => $externalId,
                'idempotencyKey' => $message->idempotencyKey,
                'status' => $status,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return SendResult::accepted($externalId);
    }

    public function health(): ProviderHealth
    {
        return new ProviderHealth(true, 'Mock provider is always available (simulated).');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(WhatsAppAccount $account, string $type, array $payload): void
    {
        $this->recordGatewayEvent->handle(new GatewayEventData(
            eventId: (string) Str::ulid(),
            type: $type,
            schemaVersion: 1,
            sessionId: $account->sessionId(),
            occurredAt: now(),
            payload: $payload,
        ));
    }
}
