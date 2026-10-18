import { Form, Head, setLayoutProps } from '@inertiajs/react';
import DeviceController from '@/actions/App/Http/Controllers/Devices/DeviceController';
import DeviceStatusBadge, {
    deviceStatusLabel,
} from '@/components/devices/device-status-badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDeviceUpdates } from '@/hooks/use-device-updates';
import type { Device, DeviceConnectionEvent } from '@/types';

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
});

export default function DeviceShow({
    device,
    history,
}: {
    device: Device;
    history: DeviceConnectionEvent[];
}) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Devices', href: DeviceController.index() },
            { title: device.name, href: DeviceController.show(device.id) },
        ],
    });
    useDeviceUpdates(['device', 'history']);

    return (
        <>
            <Head title={device.name} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-6">
                <div className="space-y-2">
                    <Heading
                        title={device.name}
                        description={device.provider_label}
                    />
                    <DeviceStatusBadge status={device.status} />
                </div>

                <dl className="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt className="text-muted-foreground">Phone</dt>
                        <dd>{device.phone_number ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Account name</dt>
                        <dd>{device.display_name ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">
                            Last connected
                        </dt>
                        <dd>
                            {device.connected_at
                                ? dateTime.format(new Date(device.connected_at))
                                : '—'}
                        </dd>
                    </div>
                </dl>

                <section className="space-y-3">
                    <h2 className="text-base font-medium">Name</h2>
                    <Form
                        {...DeviceController.update.form(device.id)}
                        options={{ preserveScroll: true }}
                        className="flex max-w-md items-start gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid flex-1 gap-1">
                                    <Label htmlFor="name" className="sr-only">
                                        Device name
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={device.name}
                                        required
                                        maxLength={100}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <Button variant="outline" disabled={processing}>
                                    Save
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-medium">
                        Connection history
                    </h2>
                    {history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No connection activity yet.
                        </p>
                    ) : (
                        <ol className="divide-y rounded-xl border text-sm">
                            {history.map((event) => (
                                <li
                                    key={event.id}
                                    className="flex flex-col gap-1 p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <span>
                                        {event.from_status
                                            ? `${deviceStatusLabel(event.from_status)} → `
                                            : ''}
                                        <span className="font-medium">
                                            {deviceStatusLabel(event.to_status)}
                                        </span>
                                        {event.reason && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {event.reason}
                                            </span>
                                        )}
                                    </span>
                                    <time
                                        dateTime={event.occurred_at}
                                        className="text-muted-foreground tabular-nums"
                                    >
                                        {dateTime.format(
                                            new Date(event.occurred_at),
                                        )}
                                    </time>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </div>
        </>
    );
}
