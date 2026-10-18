<?php

namespace App\Actions\Devices;

use App\Enums\DeviceStatus;
use App\Events\DeviceStatusChanged;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\DeviceQrStore;
use Illuminate\Support\Facades\DB;

/**
 * The only place a device's status changes. Enforces DeviceStatus transitions and records
 * connection history. Invalid (e.g. stale, out-of-order) transitions are refused.
 */
class ApplyDeviceStatus
{
    public function __construct(private DeviceQrStore $qrStore) {}

    /**
     * @param  array{phone_number?: string|null, wa_jid?: string|null, display_name?: string|null}  $attributes
     */
    public function handle(WhatsAppAccount $account, DeviceStatus $to, ?string $reason = null, array $attributes = []): bool
    {
        $applied = DB::transaction(function () use ($account, $to, $reason, $attributes): bool {
            $locked = WhatsAppAccount::query()->lockForUpdate()->findOrFail($account->id);
            $from = $locked->status;

            if ($from !== $to && ! $from->canTransitionTo($to)) {
                return false;
            }

            $locked->fill(array_filter($attributes, fn (?string $value): bool => $value !== null));
            $locked->status = $to;
            $locked->last_error = in_array($to, [DeviceStatus::Failed, DeviceStatus::Disconnected, DeviceStatus::Reconnecting, DeviceStatus::LoggedOut], true)
                ? $reason
                : null;

            if ($to === DeviceStatus::Connected) {
                $locked->connected_at = now();
            }

            if ($from !== $to) {
                $locked->status_changed_at = now();
                $locked->connectionEvents()->create([
                    'from_status' => $from,
                    'to_status' => $to,
                    'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
                    'occurred_at' => now(),
                ]);
            }

            $locked->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            DeviceStatusChanged::dispatch($locked);

            return true;
        });

        if ($applied && $to !== DeviceStatus::QrReady) {
            $this->qrStore->forget($account);
        }

        return $applied;
    }
}
