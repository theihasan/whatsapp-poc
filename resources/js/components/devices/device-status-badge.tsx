import { cn } from '@/lib/utils';
import type { DeviceStatus } from '@/types';

const labels: Record<DeviceStatus, string> = {
    created: 'Not connected',
    connecting: 'Connecting',
    qr_ready: 'Waiting for scan',
    connected: 'Connected',
    reconnecting: 'Reconnecting',
    disconnected: 'Disconnected',
    logged_out: 'Logged out',
    failed: 'Failed',
};

const dotClasses: Record<DeviceStatus, string> = {
    created: 'bg-neutral-400',
    connecting: 'bg-amber-500 animate-pulse',
    qr_ready: 'bg-sky-500 animate-pulse',
    connected: 'bg-emerald-500',
    reconnecting: 'bg-amber-500 animate-pulse',
    disconnected: 'bg-neutral-400',
    logged_out: 'bg-neutral-400',
    failed: 'bg-red-500',
};

export function deviceStatusLabel(status: DeviceStatus): string {
    return labels[status];
}

export default function DeviceStatusBadge({
    status,
    className,
}: {
    status: DeviceStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground',
                className,
            )}
        >
            <span
                aria-hidden
                className={cn('size-2 rounded-full', dotClasses[status])}
            />
            {labels[status]}
        </span>
    );
}
