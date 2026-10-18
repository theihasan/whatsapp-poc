<?php

namespace App\Services\Messaging;

use App\Actions\Gateway\RecordGatewayEvent;
use App\Contracts\MessagingProvider;
use App\DTOs\GatewayEventData;
use App\DTOs\OutgoingMessage;
use App\DTOs\ProviderCapabilities;
use App\DTOs\ProviderHealth;
use App\DTOs\SendResult;
use App\Enums\MessageType;
use App\Enums\MessagingProviderName;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adapter for Meta's WhatsApp Business Cloud API (documented Graph endpoints only).
 *
 * Scope: plain-text sends to phone numbers and a phone-number lookup used as the
 * "connect" check. Media upload, approved message templates and inbound Cloud webhooks
 * are not implemented. Unverified against a live account (no credentials available).
 */
class OfficialCloudProvider implements MessagingProvider
{
    public function __construct(private RecordGatewayEvent $recordGatewayEvent) {}

    public function name(): MessagingProviderName
    {
        return MessagingProviderName::OfficialCloud;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            usesQrPairing: false,
            supportsMedia: false,
            supportsAutoReply: true,
            supportsBulkMessaging: true,
            isSimulated: false,
            idempotentSends: false,
        );
    }

    public function connect(WhatsAppAccount $account): void
    {
        try {
            $response = $this->graph()->get($this->phoneNumberId(), ['fields' => 'display_phone_number,verified_name']);
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailableException('The WhatsApp Cloud API is unreachable.', previous: $exception);
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException('The WhatsApp Cloud API rejected the credentials ('.$response->status().').');
        }

        $this->recordGatewayEvent->handle(new GatewayEventData(
            eventId: (string) Str::ulid(),
            type: 'session.connected',
            schemaVersion: 1,
            sessionId: $account->sessionId(),
            occurredAt: now(),
            payload: [
                'phoneNumber' => $response->json('display_phone_number'),
                'jid' => null,
                'displayName' => $response->json('verified_name'),
            ],
        ));
    }

    public function disconnect(WhatsAppAccount $account): void
    {
        $this->emitStatus($account, 'session.disconnected', 'manual');
    }

    public function revoke(WhatsAppAccount $account): void
    {
        $this->emitStatus($account, 'session.logged_out', 'revoked');
    }

    public function sendMessage(WhatsAppAccount $account, OutgoingMessage $message): SendResult
    {
        if ($message->type !== MessageType::Text) {
            return SendResult::rejected('The Cloud API adapter only supports text messages.');
        }

        $recipient = Str::before($message->recipientJid, '@');

        try {
            $response = $this->graph()->post($this->phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['body' => $message->body],
            ]);
        } catch (ConnectionException $exception) {
            if (GatewayClient::failedBeforeSending($exception)) {
                throw new ProviderUnavailableException('The WhatsApp Cloud API is unreachable.', previous: $exception);
            }

            return SendResult::unknown('The Cloud API did not respond in time; the message may or may not have been sent.');
        }

        $messageId = $response->json('messages.0.id');

        return match (true) {
            $response->successful() && is_string($messageId) => SendResult::accepted($messageId),
            $response->clientError() => SendResult::rejected((string) $response->json('error.message', 'The Cloud API rejected the message.')),
            default => SendResult::unknown('Unexpected Cloud API response ('.$response->status().').'),
        };
    }

    public function health(): ProviderHealth
    {
        return filled(config('messaging.official.access_token'))
            ? new ProviderHealth(true, 'Credentials configured (not verified live).')
            : new ProviderHealth(false, 'Credentials not configured.');
    }

    private function graph(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('messaging.official.graph_url'), '/').'/'.config('messaging.official.api_version'))
            ->withToken((string) config('messaging.official.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('messaging.official.timeout'));
    }

    private function phoneNumberId(): string
    {
        return (string) config('messaging.official.phone_number_id');
    }

    private function emitStatus(WhatsAppAccount $account, string $type, string $reason): void
    {
        $this->recordGatewayEvent->handle(new GatewayEventData(
            eventId: (string) Str::ulid(),
            type: $type,
            schemaVersion: 1,
            sessionId: $account->sessionId(),
            occurredAt: now(),
            payload: ['reason' => $reason],
        ));
    }
}
