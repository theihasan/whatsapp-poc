import { router } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { useCallback, useEffect, useState } from 'react';
import DeviceSessionController from '@/actions/App/Http/Controllers/Devices/DeviceSessionController';
import MockDeviceSimulationController from '@/actions/App/Http/Controllers/Devices/MockDeviceSimulationController';
import DeviceStatusBadge from '@/components/devices/device-status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { Device, DeviceStatus } from '@/types';

type QrState = {
    qr: string | null;
    expires_at: string | null;
    status: DeviceStatus;
};

const POLL_INTERVAL_MS = 3000;

/**
 * Shows the temporary pairing QR. It fetches the code from an authenticated route
 * (QR codes are never broadcast) and polls as a fallback when WebSockets are down.
 */
export default function QrDialog({
    device,
    open,
    onOpenChange,
}: {
    device: Device;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [state, setState] = useState<QrState | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [now, setNow] = useState(() => Date.now());

    const refresh = useCallback(async () => {
        try {
            const response = await fetch(
                DeviceSessionController.qr.url(device.id),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            setState((await response.json()) as QrState);
            setError(null);
        } catch {
            setError('Could not load the QR code. Retrying…');
        }
    }, [device.id]);

    useEffect(() => {
        if (!open) {
            return;
        }

        void refresh();
        const poll = window.setInterval(() => void refresh(), POLL_INTERVAL_MS);
        const tick = window.setInterval(() => setNow(Date.now()), 1000);

        return () => {
            window.clearInterval(poll);
            window.clearInterval(tick);
        };
    }, [open, refresh, device.status]);

    useEffect(() => {
        if (open && device.status === 'connected') {
            onOpenChange(false);
        }
    }, [open, device.status, onOpenChange]);

    const secondsLeft = state?.expires_at
        ? Math.max(0, Math.round((Date.parse(state.expires_at) - now) / 1000))
        : 0;
    const expired = state !== null && (state.qr === null || secondsLeft === 0);
    const isMock = device.provider === 'mock';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Link {device.name}</DialogTitle>
                    <DialogDescription>
                        On your phone open WhatsApp → Settings → Linked devices
                        → Link a device, then scan this code.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col items-center gap-3 py-2">
                    <div className="flex size-64 items-center justify-center rounded-lg border bg-white p-3">
                        {state?.qr && !expired ? (
                            <QRCodeSVG
                                value={state.qr}
                                size={232}
                                aria-label="WhatsApp pairing QR code"
                            />
                        ) : expired ? (
                            <p className="px-6 text-center text-sm text-neutral-600">
                                This code expired.
                                {device.provider === 'unofficial_web'
                                    ? ' A new one appears automatically while pairing is active.'
                                    : ''}
                            </p>
                        ) : (
                            <Spinner />
                        )}
                    </div>

                    <div className="flex items-center gap-3 text-sm">
                        <DeviceStatusBadge status={device.status} />
                        {state?.qr && !expired && (
                            <span className="text-muted-foreground tabular-nums">
                                Expires in {secondsLeft}s
                            </span>
                        )}
                    </div>

                    {error && (
                        <p role="alert" className="text-sm text-red-600">
                            {error}
                        </p>
                    )}

                    <p className="text-center text-xs text-muted-foreground">
                        Scanning only links the phone. The device shows as
                        connected once WhatsApp confirms the session.
                    </p>
                </div>

                <DialogFooter className="gap-2 sm:justify-between">
                    {expired && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    DeviceSessionController.connect.url(
                                        device.id,
                                    ),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => void refresh(),
                                    },
                                )
                            }
                        >
                            Generate new code
                        </Button>
                    )}
                    {isMock && state?.qr && !expired && (
                        <Button
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    MockDeviceSimulationController.scan.url(
                                        device.id,
                                    ),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Simulate scan
                        </Button>
                    )}
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
