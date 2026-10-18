<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppAccount;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;

/**
 * Short-lived QR storage. QR strings never reach the database, logs or broadcasts.
 */
class DeviceQrStore
{
    public function __construct(private Repository $cache) {}

    public function put(WhatsAppAccount $account, string $qr, int $ttlSeconds): void
    {
        $ttlSeconds = max(5, min($ttlSeconds, (int) config('messaging.qr_ttl')));

        $this->cache->put($this->key($account), [
            'qr' => $qr,
            'expires_at' => now()->addSeconds($ttlSeconds)->toIso8601String(),
        ], $ttlSeconds);
    }

    /**
     * @return array{qr: string, expires_at: CarbonInterface}|null
     */
    public function get(WhatsAppAccount $account): ?array
    {
        $value = $this->cache->get($this->key($account));

        if (! is_array($value) || ! is_string($value['qr'] ?? null)) {
            return null;
        }

        return ['qr' => $value['qr'], 'expires_at' => Date::parse($value['expires_at'])];
    }

    public function forget(WhatsAppAccount $account): void
    {
        $this->cache->forget($this->key($account));
    }

    private function key(WhatsAppAccount $account): string
    {
        return 'device-qr:'.$account->id;
    }
}
