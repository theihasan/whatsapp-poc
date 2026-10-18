<?php

namespace App\Actions\Devices;

use App\Enums\DeviceStatus;
use App\Exceptions\DeviceActionException;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;
use App\Services\AuditLogger;
use App\Services\Messaging\MessagingProviderRegistry;

/**
 * Logs the linked device out and deletes its stored protocol credentials. A new QR scan
 * is required to use the device again.
 */
class RevokeWhatsAppAccount
{
    public function __construct(
        private MessagingProviderRegistry $providers,
        private ApplyDeviceStatus $applyDeviceStatus,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws DeviceActionException
     */
    public function handle(WhatsAppAccount $account): void
    {
        if ($account->status === DeviceStatus::Created) {
            return;
        }

        try {
            $this->providers->for($account)->revoke($account);
        } catch (ProviderUnavailableException $exception) {
            throw new DeviceActionException($exception->getMessage(), previous: $exception);
        }

        $this->applyDeviceStatus->handle($account, DeviceStatus::LoggedOut, 'Session revoked');
        $this->audit->record('device.revoked', $account);
    }
}
