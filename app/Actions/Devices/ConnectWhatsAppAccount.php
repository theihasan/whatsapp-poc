<?php

namespace App\Actions\Devices;

use App\Enums\DeviceStatus;
use App\Exceptions\DeviceActionException;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;
use App\Services\AuditLogger;
use App\Services\Messaging\DeviceQrStore;
use App\Services\Messaging\MessagingProviderRegistry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Starts or resumes a device session. A cache lock plus the status check guarantee a
 * single concurrent connection attempt per session. A device whose QR code expired
 * unscanned may restart pairing.
 */
class ConnectWhatsAppAccount
{
    public function __construct(
        private MessagingProviderRegistry $providers,
        private ApplyDeviceStatus $applyDeviceStatus,
        private DeviceQrStore $qrStore,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws DeviceActionException
     */
    public function handle(WhatsAppAccount $account): void
    {
        try {
            Cache::lock('device-connect:'.$account->id, 30)->block(5, function () use ($account): void {
                $account->refresh();

                $qrExpired = $account->status === DeviceStatus::QrReady && $this->qrStore->get($account) === null;

                if (! $account->status->canConnect() && ! $qrExpired) {
                    throw new DeviceActionException('This device is already connecting or connected.');
                }

                $provider = $this->providers->for($account);
                $this->applyDeviceStatus->handle($account, DeviceStatus::Connecting, 'Connection requested');

                try {
                    $provider->connect($account);
                } catch (ProviderUnavailableException $exception) {
                    $this->applyDeviceStatus->handle($account, DeviceStatus::Failed, $exception->getMessage());

                    throw new DeviceActionException($exception->getMessage(), previous: $exception);
                }

                $this->audit->record('device.connect_requested', $account, ['provider' => $account->provider->value]);
            });
        } catch (LockTimeoutException) {
            throw new DeviceActionException('Another connection attempt for this device is in progress.');
        }
    }
}
