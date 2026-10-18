<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates gateway webhooks: HMAC-SHA256 over "{timestamp}.{raw body}", a bounded
 * timestamp window, a body size limit, and an optional previous secret for rotation.
 * Replays inside the window are rejected later by the unique gateway event id.
 */
class VerifyGatewaySignature
{
    public const MAX_BODY_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_values(array_filter([
            config('messaging.gateway.webhook_secret'),
            config('messaging.gateway.webhook_secret_previous'),
        ], fn (mixed $secret): bool => is_string($secret) && $secret !== ''));

        if ($secrets === []) {
            return $this->reject('webhook_not_configured', 'Gateway webhooks are not configured.', 503);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->reject('payload_too_large', 'Payload too large.', 413);
        }

        $timestamp = (string) $request->header('X-Gateway-Timestamp', '');
        $signature = (string) $request->header('X-Gateway-Signature', '');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) config('messaging.gateway.signature_tolerance')) {
            return $this->reject('invalid_timestamp', 'Missing or expired timestamp.', 401);
        }

        foreach ($secrets as $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        return $this->reject('invalid_signature', 'Invalid signature.', 401);
    }

    private function reject(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
