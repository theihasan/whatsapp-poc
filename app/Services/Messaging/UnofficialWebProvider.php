<?php

namespace App\Services\Messaging;

use App\Contracts\MessagingProvider;
use App\DTOs\OutgoingMessage;
use App\DTOs\ProviderCapabilities;
use App\DTOs\ProviderHealth;
use App\DTOs\SendResult;
use App\Enums\MessagingProviderName;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;

/**
 * Baileys-backed provider via the Node gateway. Unofficial: for learning and controlled
 * testing with consenting test accounts only. Never used for bulk messaging.
 */
class UnofficialWebProvider implements MessagingProvider
{
    public function __construct(private GatewayClient $gateway) {}

    public function name(): MessagingProviderName
    {
        return MessagingProviderName::UnofficialWeb;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            usesQrPairing: true,
            supportsMedia: true,
            supportsAutoReply: true,
            supportsBulkMessaging: false,
            isSimulated: false,
            idempotentSends: true,
        );
    }

    public function connect(WhatsAppAccount $account): void
    {
        $this->gateway->command('POST', '/v1/sessions', ['sessionId' => $account->sessionId()]);
    }

    public function disconnect(WhatsAppAccount $account): void
    {
        $this->gateway->command('POST', "/v1/sessions/{$account->sessionId()}/disconnect");
    }

    public function revoke(WhatsAppAccount $account): void
    {
        $this->gateway->command('DELETE', "/v1/sessions/{$account->sessionId()}");
    }

    public function sendMessage(WhatsAppAccount $account, OutgoingMessage $message): SendResult
    {
        $payload = [
            'idempotencyKey' => $message->idempotencyKey,
            'to' => $message->recipientJid,
            'type' => $message->type->value,
            'text' => $message->body,
        ];

        if ($message->attachment !== null) {
            $payload['media'] = [
                'fileName' => $message->attachment->fileName,
                'mimeType' => $message->attachment->mimeType,
                'base64' => $message->attachment->base64Contents,
            ];
        }

        try {
            $response = $this->gateway->request()->post("/v1/sessions/{$account->sessionId()}/messages", $payload);
        } catch (ConnectionException $exception) {
            if (GatewayClient::failedBeforeSending($exception)) {
                throw new ProviderUnavailableException('The WhatsApp gateway is unreachable.', previous: $exception);
            }

            return SendResult::unknown('The gateway did not respond in time; the message may or may not have been sent.');
        }

        $code = (string) $response->json('error.code', '');

        return match (true) {
            $response->successful() && is_string($response->json('messageId')) => SendResult::accepted($response->json('messageId')),
            $response->status() === 409 && $code === 'session_not_connected' => throw new ProviderUnavailableException('The WhatsApp session is not connected.'),
            $response->status() === 422, $response->status() === 400 => SendResult::rejected((string) $response->json('error.message', 'The gateway rejected the message.')),
            default => SendResult::unknown(sprintf('Unexpected gateway response (%d %s).', $response->status(), $code ?: 'error')),
        };
    }

    /**
     * Phone-number identities for LIDs the linked device already knows (gateway-local
     * mapping store; no lookup on WhatsApp).
     *
     * @param  list<string>  $lids
     * @return list<array{lid: string, jid: string, phoneNumber: string}>
     *
     * @throws ProviderUnavailableException
     */
    public function resolveContactIdentities(WhatsAppAccount $account, array $lids): array
    {
        $identities = $this->gateway
            ->command('POST', "/v1/sessions/{$account->sessionId()}/contacts/resolve", ['lids' => $lids])
            ->json('identities', []);

        return array_values(array_filter(
            is_array($identities) ? $identities : [],
            fn (mixed $identity): bool => is_array($identity)
                && is_string($identity['lid'] ?? null)
                && is_string($identity['jid'] ?? null)
                && is_string($identity['phoneNumber'] ?? null),
        ));
    }

    public function health(): ProviderHealth
    {
        try {
            $response = $this->gateway->request()->timeout(3)->get('/ready');
        } catch (ConnectionException) {
            return new ProviderHealth(false, 'Gateway unreachable.');
        }

        return $response->successful()
            ? new ProviderHealth(true, 'Gateway ready.')
            : new ProviderHealth(false, 'Gateway not ready ('.$response->status().').');
    }
}
