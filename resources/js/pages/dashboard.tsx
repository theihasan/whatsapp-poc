import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import DeviceController from '@/actions/App/Http/Controllers/Devices/DeviceController';
import ActivityChart from '@/components/dashboard/activity-chart';
import StatTile from '@/components/dashboard/stat-tile';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatListTimestamp } from '@/lib/format';
import { dashboard, inbox } from '@/routes';
import { read as readNotifications } from '@/routes/notifications';
import { show as showConversation } from '@/routes/inbox';

type Metrics = {
    generated_at: string;
    simulated: boolean;
    devices: { connected: number; total: number };
    conversations: { active: number; unread: number; unassigned_open: number };
    messages_7d: {
        incoming: number;
        outgoing: number;
        failed: number;
        unknown: number;
    };
    activity: { date: string; incoming: number; outgoing: number }[];
    workload: { name: string; open: number }[];
    gateway_events: { pending: number; failed: number };
    failed_jobs: number;
};

type HealthCheck = { name: string; healthy: boolean; summary: string };

type NotificationItem = {
    id: string;
    data: { title: string; conversation_id: string };
    created_at: string | null;
};

export default function Dashboard({
    metrics,
    health,
    notifications,
}: {
    metrics: Metrics;
    health?: HealthCheck[];
    notifications: NotificationItem[];
}) {
    const maxWorkload = Math.max(
        1,
        ...metrics.workload.map((entry) => entry.open),
    );

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                {metrics.simulated && (
                    <p
                        role="note"
                        className="rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground"
                    >
                        Includes simulated data from mock-provider devices.
                        Figures refresh every minute (as of{' '}
                        {formatListTimestamp(metrics.generated_at)}).
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Connected devices"
                        value={`${metrics.devices.connected} / ${metrics.devices.total}`}
                        href={DeviceController.index()}
                    />
                    <StatTile
                        label="Active conversations"
                        value={metrics.conversations.active}
                        detail={`${metrics.conversations.unassigned_open} open & unassigned`}
                        href={inbox()}
                    />
                    <StatTile
                        label="Unread conversations"
                        value={metrics.conversations.unread}
                        href={inbox({ query: { filter: 'unread' } })}
                    />
                    <StatTile
                        label="Failed or unconfirmed (7 days)"
                        value={
                            metrics.messages_7d.failed +
                            metrics.messages_7d.unknown
                        }
                        detail={`${metrics.messages_7d.failed} failed · ${metrics.messages_7d.unknown} unconfirmed`}
                        tone={
                            metrics.messages_7d.failed +
                                metrics.messages_7d.unknown >
                            0
                                ? 'attention'
                                : 'default'
                        }
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="space-y-3 rounded-xl border p-4 lg:col-span-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="font-medium">Message activity</h2>
                            <p className="text-xs text-muted-foreground tabular-nums">
                                Last 7 days: {metrics.messages_7d.incoming} in ·{' '}
                                {metrics.messages_7d.outgoing} out
                            </p>
                        </div>
                        <ActivityChart days={metrics.activity} />
                    </section>

                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">Agent workload</h2>
                        <p className="text-xs text-muted-foreground">
                            Open conversations per assignee
                        </p>
                        {metrics.workload.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No assigned conversations.
                            </p>
                        ) : (
                            <ul className="viz-root space-y-2">
                                {metrics.workload.map((entry) => (
                                    <li key={entry.name} className="text-sm">
                                        <div className="flex justify-between">
                                            <span>{entry.name}</span>
                                            <span className="tabular-nums">
                                                {entry.open}
                                            </span>
                                        </div>
                                        <div className="mt-1 h-2 rounded-full bg-muted">
                                            <div
                                                className="h-2 rounded-full"
                                                style={{
                                                    width: `${(entry.open / maxWorkload) * 100}%`,
                                                    background:
                                                        'var(--series-incoming)',
                                                }}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-medium">System health</h2>
                        {health === undefined ? (
                            <div className="space-y-2">
                                <Skeleton className="h-5 w-2/3" />
                                <Skeleton className="h-5 w-1/2" />
                                <Skeleton className="h-5 w-3/5" />
                            </div>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {health.map((check) => (
                                    <li
                                        key={check.name}
                                        className="flex items-start gap-2"
                                    >
                                        {check.healthy ? (
                                            <CheckCircle2
                                                className="mt-0.5 size-4 text-emerald-600"
                                                aria-label="Healthy"
                                            />
                                        ) : (
                                            <XCircle
                                                className="mt-0.5 size-4 text-red-600"
                                                aria-label="Unhealthy"
                                            />
                                        )}
                                        <span>
                                            <span className="font-medium">
                                                {check.name}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {check.summary}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                                <li className="pt-1 text-xs text-muted-foreground">
                                    Gateway events pending{' '}
                                    {metrics.gateway_events.pending} · failed{' '}
                                    {metrics.gateway_events.failed} · failed
                                    jobs {metrics.failed_jobs}
                                </li>
                            </ul>
                        )}
                    </section>

                    <section className="space-y-3 rounded-xl border p-4">
                        <div className="flex items-center justify-between">
                            <h2 className="font-medium">Notifications</h2>
                            {notifications.length > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            readNotifications.url(),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Mark all read
                                </Button>
                            )}
                        </div>
                        {notifications.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                You're all caught up.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {notifications.map((notification) => (
                                    <li key={notification.id} className="py-2">
                                        <Link
                                            href={showConversation.url(
                                                notification.data
                                                    .conversation_id,
                                            )}
                                            className="hover:underline"
                                        >
                                            {notification.data.title}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {formatListTimestamp(
                                                notification.created_at,
                                            )}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
