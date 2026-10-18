<?php

namespace App\Http\Controllers\Devices;

use App\Actions\Devices\ConnectWhatsAppAccount;
use App\Actions\Devices\DisconnectWhatsAppAccount;
use App\Actions\Devices\RevokeWhatsAppAccount;
use App\Exceptions\DeviceActionException;
use App\Exceptions\ProviderNotEnabledException;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\DeviceQrStore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DeviceSessionController extends Controller
{
    /**
     * Start or resume the device session (shows a QR when pairing is needed).
     */
    public function connect(WhatsAppAccount $device, ConnectWhatsAppAccount $connect): RedirectResponse
    {
        return $this->attempt(fn () => $connect->handle($device), __('Connecting…'));
    }

    /**
     * Close the session, keeping credentials for later resume.
     */
    public function disconnect(WhatsAppAccount $device, DisconnectWhatsAppAccount $disconnect): RedirectResponse
    {
        return $this->attempt(fn () => $disconnect->handle($device), __('Disconnect requested.'));
    }

    /**
     * Log out the linked device and delete stored credentials.
     */
    public function revoke(WhatsAppAccount $device, RevokeWhatsAppAccount $revoke): RedirectResponse
    {
        return $this->attempt(fn () => $revoke->handle($device), __('Session revoked.'));
    }

    /**
     * Current temporary QR code, if one is pending.
     */
    public function qr(WhatsAppAccount $device, DeviceQrStore $qrStore): JsonResponse
    {
        $qr = $qrStore->get($device);

        return response()
            ->json($qr === null
                ? ['qr' => null, 'expires_at' => null, 'status' => $device->status->value]
                : ['qr' => $qr['qr'], 'expires_at' => $qr['expires_at']->toIso8601String(), 'status' => $device->status->value])
            ->header('Cache-Control', 'no-store');
    }

    private function attempt(Closure $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
            Inertia::flash('toast', ['type' => 'success', 'message' => $successMessage]);
        } catch (DeviceActionException|ProviderNotEnabledException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back();
    }
}
