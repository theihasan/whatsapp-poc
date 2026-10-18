<?php

namespace App\Http\Controllers\Devices;

use App\Actions\Devices\RevokeWhatsAppAccount;
use App\Enums\MessagingProviderName;
use App\Exceptions\DeviceActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\StoreDeviceRequest;
use App\Http\Requests\Devices\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\DeviceConnectionEvent;
use App\Models\WhatsAppAccount;
use App\Services\AuditLogger;
use App\Services\Messaging\MessagingProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    /**
     * List linked devices.
     */
    public function index(MessagingProviderRegistry $providers): Response
    {
        return Inertia::render('devices/index', [
            'devices' => DeviceResource::collection(WhatsAppAccount::query()->orderBy('created_at')->orderBy('id')->get())->resolve(),
            'providers' => array_map(fn (MessagingProviderName $name): array => [
                'value' => $name->value,
                'label' => $name->label(),
            ], $providers->enabledProviders()),
            'defaultProvider' => $providers->defaultProvider()->value,
        ]);
    }

    /**
     * Show a device with its connection history.
     */
    public function show(WhatsAppAccount $device): Response
    {
        return Inertia::render('devices/show', [
            'device' => (new DeviceResource($device))->resolve(),
            'history' => $device->connectionEvents()
                ->latest('occurred_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (DeviceConnectionEvent $event): array => [
                    'id' => $event->id,
                    'from_status' => $event->from_status?->value,
                    'to_status' => $event->to_status->value,
                    'reason' => $event->reason,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Register a new device. Pairing starts separately via "connect".
     */
    public function store(StoreDeviceRequest $request, AuditLogger $audit): RedirectResponse
    {
        $device = WhatsAppAccount::query()->create([
            'name' => $request->string('name')->toString(),
            'provider' => MessagingProviderName::from($request->string('provider')->toString()),
        ]);

        $audit->record('device.created', $device, ['provider' => $device->provider->value]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device added.')]);

        return to_route('devices.index');
    }

    /**
     * Rename a device.
     */
    public function update(UpdateDeviceRequest $request, WhatsAppAccount $device, AuditLogger $audit): RedirectResponse
    {
        $device->update($request->validated());
        $audit->record('device.renamed', $device);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device renamed.')]);

        return back();
    }

    /**
     * Revoke the session (if any) and delete the device with its conversations.
     */
    public function destroy(WhatsAppAccount $device, RevokeWhatsAppAccount $revoke, AuditLogger $audit): RedirectResponse
    {
        try {
            $revoke->handle($device);
        } catch (DeviceActionException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not revoke the session: :reason', ['reason' => $exception->getMessage()])]);

            return back();
        }

        $audit->record('device.deleted', $device);
        $device->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device removed.')]);

        return to_route('devices.index');
    }
}
