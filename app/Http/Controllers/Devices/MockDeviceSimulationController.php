<?php

namespace App\Http\Controllers\Devices;

use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\MockMessagingProvider;
use App\Support\FakePhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Demo-only controls for devices on the mock provider.
 */
class MockDeviceSimulationController extends Controller
{
    public function __construct(private MockMessagingProvider $mock) {}

    /**
     * Pretend the QR code was scanned by a phone.
     */
    public function scan(WhatsAppAccount $device): RedirectResponse
    {
        $this->ensureMock($device);

        if ($device->status !== DeviceStatus::QrReady) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('There is no QR code waiting to be scanned.')]);

            return back();
        }

        $this->mock->simulateScan($device);

        return back();
    }

    /**
     * Pretend a contact sent a message to this device.
     */
    public function incomingMessage(Request $request, WhatsAppAccount $device): RedirectResponse
    {
        $this->ensureMock($device);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'phone_number' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        if ($device->status !== DeviceStatus::Connected) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Connect the device first.')]);

            return back();
        }

        $this->mock->simulateIncomingMessage(
            $device,
            $validated['phone_number'] ?? FakePhone::number(),
            $validated['name'] ?? null,
            $validated['body'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Simulated message received.')]);

        return back();
    }

    private function ensureMock(WhatsAppAccount $device): void
    {
        abort_unless($device->provider === MessagingProviderName::Mock, 404);
    }
}
