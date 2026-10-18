import { Head } from '@inertiajs/react';
import { useState } from 'react';
import DeviceController from '@/actions/App/Http/Controllers/Devices/DeviceController';
import AddDeviceDialog from '@/components/devices/add-device-dialog';
import DeviceCard from '@/components/devices/device-card';
import QrDialog from '@/components/devices/qr-dialog';
import Heading from '@/components/heading';
import { useDeviceUpdates } from '@/hooks/use-device-updates';
import type { Device, ProviderOption } from '@/types';

export default function DevicesIndex({
    devices,
    providers,
    defaultProvider,
}: {
    devices: Device[];
    providers: ProviderOption[];
    defaultProvider: string;
}) {
    const [qrDeviceId, setQrDeviceId] = useState<string | null>(null);
    const qrDevice = devices.find((device) => device.id === qrDeviceId) ?? null;

    useDeviceUpdates(['devices']);

    return (
        <>
            <Head title="Devices" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Devices"
                        description="WhatsApp numbers linked to this workspace."
                    />
                    <AddDeviceDialog
                        providers={providers}
                        defaultProvider={defaultProvider}
                    />
                </div>

                {devices.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <p className="font-medium">No devices yet</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Add a device, then connect it to start receiving
                            messages. The mock provider works without a phone.
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        {devices.map((device) => (
                            <DeviceCard
                                key={device.id}
                                device={device}
                                onShowQr={() => setQrDeviceId(device.id)}
                            />
                        ))}
                    </div>
                )}
            </div>

            {qrDevice && (
                <QrDialog
                    device={qrDevice}
                    open={qrDeviceId !== null}
                    onOpenChange={(open) => !open && setQrDeviceId(null)}
                />
            )}
        </>
    );
}

DevicesIndex.layout = {
    breadcrumbs: [{ title: 'Devices', href: DeviceController.index() }],
};
