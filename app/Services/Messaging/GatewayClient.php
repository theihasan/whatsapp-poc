<?php

namespace App\Services\Messaging;

use App\Exceptions\ProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Authenticated HTTP client for the internal WhatsApp gateway (docs/gateway-api.md).
 */
class GatewayClient
{
    public function request(): PendingRequest
    {
        return Http::baseUrl((string) config('messaging.gateway.url'))
            ->withToken((string) config('messaging.gateway.token'))
            ->withHeaders(['X-Correlation-Id' => $this->correlationId()])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('messaging.gateway.connect_timeout'))
            ->timeout((int) config('messaging.gateway.timeout'));
    }

    /**
     * Send a lifecycle command where any failure means the command did not take effect.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ProviderUnavailableException
     */
    public function command(string $method, string $path, array $payload = []): Response
    {
        try {
            $response = $this->request()->send($method, $path, ['json' => $payload === [] ? new \stdClass : $payload]);
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailableException('The WhatsApp gateway is unreachable.', previous: $exception);
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException(sprintf(
                'The WhatsApp gateway rejected the request (%d %s).',
                $response->status(),
                (string) $response->json('error.code', 'error'),
            ));
        }

        return $response;
    }

    /**
     * Whether a connection exception happened before the request could have been delivered
     * (DNS failure or refused connection), as opposed to a timeout after sending.
     */
    public static function failedBeforeSending(ConnectionException $exception): bool
    {
        return Str::contains($exception->getMessage(), [
            'cURL error 6:',
            'cURL error 7:',
            'Could not resolve host',
            'Connection refused',
            'Failed to connect',
        ]);
    }

    private function correlationId(): string
    {
        $correlationId = Context::get('correlation_id');

        return is_string($correlationId) ? $correlationId : (string) Str::uuid();
    }
}
