import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import type { DeviceStatus } from '@/types';

export type DeviceStatusChangedPayload = {
    id: string;
    status: DeviceStatus;
    phone_number: string | null;
    display_name: string | null;
    last_error: string | null;
    status_changed_at: string | null;
};

/**
 * Subscribes to device status broadcasts and reloads the given server props.
 * The subscription is released automatically when the component unmounts.
 */
export function useDeviceUpdates(
    reloadProps: string[],
    onChange?: (payload: DeviceStatusChangedPayload) => void,
): void {
    useEcho<DeviceStatusChangedPayload>(
        'devices',
        'DeviceStatusChanged',
        (payload) => {
            onChange?.(payload);
            router.reload({ only: reloadProps });
        },
        [reloadProps.join(','), onChange],
    );
}
