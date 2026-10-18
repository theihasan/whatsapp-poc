<?php

namespace App\Actions\Devices;

use App\Exceptions\DeviceActionException;
use App\Exceptions\ProviderUnavailableException;
use App\Models\WhatsAppAccount;
use App\Services\AuditLogger;
use App\Services\Messaging\MessagingProviderRegistry;

/**
 * Closes the session but keeps stored credentials so it can be resumed without a new QR.
 */
class DisconnectWhatsAppAccount
{
    public function __construct(
        private MessagingProviderRegistry $providers,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws DeviceActionException
     */
    public function handle(WhatsAppAccount $account): void
    {
        if (! $account->status->isActive()) {
            throw new DeviceActionException('This device is not connected.');
        }

        try {
            $this->providers->for($account)->disconnect($account);
        } catch (ProviderUnavailableException $exception) {
            throw new DeviceActionException($exception->getMessage(), previous: $exception);
        }

        $this->audit->record('device.disconnected', $account);
    }
}
