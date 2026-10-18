import { Link, router } from '@inertiajs/react';
import { MoreHorizontal, Smartphone } from 'lucide-react';
import DeviceController from '@/actions/App/Http/Controllers/Devices/DeviceController';
import DeviceSessionController from '@/actions/App/Http/Controllers/Devices/DeviceSessionController';
import DeviceStatusBadge from '@/components/devices/device-status-badge';
import SimulateMessageDialog from '@/components/devices/simulate-message-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Device, DeviceStatus } from '@/types';

const connectable: DeviceStatus[] = [
    'created',
    'disconnected',
    'logged_out',
    'failed',
];
const active: DeviceStatus[] = [
    'connecting',
    'qr_ready',
    'connected',
    'reconnecting',
];

const troubleshooting: Partial<Record<DeviceStatus, string>> = {
    failed: 'The last attempt failed. Check that the gateway is running, then connect again.',
    logged_out:
        'This device was unlinked (from the phone or by revoking). Connecting needs a new QR scan.',
    reconnecting:
        'The connection dropped. The gateway retries automatically with backoff.',
};

export default function DeviceCard({
    device,
    onShowQr,
}: {
    device: Device;
    onShowQr: () => void;
}) {
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });
    const canConnect =
        device.provider_enabled && connectable.includes(device.status);
    const hint = troubleshooting[device.status];

    return (
        <div className="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <Smartphone className="size-5 text-muted-foreground" />
                </div>
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={DeviceController.show.url(device.id)}
                            className="truncate font-medium hover:underline"
                        >
                            {device.name}
                        </Link>
                        <Badge variant="outline">{device.provider_label}</Badge>
                    </div>
                    <DeviceStatusBadge status={device.status} />
                    {device.phone_number && (
                        <p className="text-sm text-muted-foreground">
                            {device.display_name
                                ? `${device.display_name} · `
                                : ''}
                            {device.phone_number}
                        </p>
                    )}
                    {device.last_error && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            {device.last_error}
                        </p>
                    )}
                    {hint && (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    )}
                    {!device.provider_enabled && (
                        <p className="text-xs text-amber-700 dark:text-amber-400">
                            This provider is disabled in the server
                            configuration.
                        </p>
                    )}
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-2">
                {device.provider === 'mock' &&
                    device.status === 'connected' && (
                        <SimulateMessageDialog device={device} />
                    )}
                {device.status === 'qr_ready' && (
                    <Button size="sm" onClick={onShowQr}>
                        Show QR code
                    </Button>
                )}
                {canConnect && (
                    <Button
                        size="sm"
                        onClick={() => {
                            post(
                                DeviceSessionController.connect.url(device.id),
                            );
                            if (device.capabilities?.usesQrPairing) {
                                onShowQr();
                            }
                        }}
                    >
                        {device.status === 'created' ? 'Connect' : 'Reconnect'}
                    </Button>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Actions for ${device.name}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <Link href={DeviceController.show.url(device.id)}>
                                Details & history
                            </Link>
                        </DropdownMenuItem>
                        {active.includes(device.status) && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    post(
                                        DeviceSessionController.disconnect.url(
                                            device.id,
                                        ),
                                    )
                                }
                            >
                                Disconnect
                            </DropdownMenuItem>
                        )}
                        {device.status !== 'created' &&
                            device.status !== 'logged_out' && (
                                <DropdownMenuItem
                                    onSelect={() => {
                                        if (
                                            window.confirm(
                                                'Revoke this session? The phone is unlinked and a new QR scan is required.',
                                            )
                                        ) {
                                            post(
                                                DeviceSessionController.revoke.url(
                                                    device.id,
                                                ),
                                            );
                                        }
                                    }}
                                >
                                    Revoke session
                                </DropdownMenuItem>
                            )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => {
                                if (
                                    window.confirm(
                                        'Remove this device and all of its conversations?',
                                    )
                                ) {
                                    router.delete(
                                        DeviceController.destroy.url(device.id),
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            Remove device
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    );
}
