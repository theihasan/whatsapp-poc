import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { activity } from '@/routes';
import type { Paginated } from '@/types';

type Log = {
    id: number;
    action: string;
    user: string | null;
    subject_type: string | null;
    subject_id: string | null;
    properties: Record<string, string | number | boolean | null> | null;
    ip_address: string | null;
    created_at: string;
};

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

export default function ActivityIndex({
    logs,
    users,
    areas,
    filters,
}: {
    logs: Paginated<Log>;
    users: { id: number; name: string }[];
    areas: string[];
    filters: { area: string | null; user: number | null };
}) {
    const visit = (next: typeof filters) =>
        router.get(
            activity.url(),
            Object.fromEntries(
                Object.entries(next).filter(([, value]) => value !== null),
            ),
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Activity" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Activity"
                    description="Audit trail of administrative actions. Message content is never recorded here."
                />

                <div className="flex flex-wrap gap-2">
                    <Select
                        value={filters.area ?? 'all'}
                        onValueChange={(area) =>
                            visit({
                                ...filters,
                                area: area === 'all' ? null : area,
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-44"
                            aria-label="Filter by area"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All areas</SelectItem>
                            {areas.map((area) => (
                                <SelectItem
                                    key={area}
                                    value={area}
                                    className="capitalize"
                                >
                                    {area}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.user ? String(filters.user) : 'all'}
                        onValueChange={(user) =>
                            visit({
                                ...filters,
                                user: user === 'all' ? null : Number(user),
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-44"
                            aria-label="Filter by user"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Everyone</SelectItem>
                            {users.map((user) => (
                                <SelectItem
                                    key={user.id}
                                    value={String(user.id)}
                                >
                                    {user.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {logs.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No activity recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        When
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Who
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Action
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">
                                        Details
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                            {dateTime.format(
                                                new Date(log.created_at),
                                            )}
                                        </td>
                                        <td className="px-4 py-2">
                                            {log.user ?? 'System'}
                                        </td>
                                        <td className="px-4 py-2 font-mono text-xs">
                                            {log.action}
                                        </td>
                                        <td className="hidden px-4 py-2 text-xs text-muted-foreground md:table-cell">
                                            {log.subject_type &&
                                                `${log.subject_type} ${log.subject_id?.slice(-6) ?? ''}`}
                                            {log.properties &&
                                                ` · ${Object.entries(
                                                    log.properties,
                                                )
                                                    .map(
                                                        ([key, value]) =>
                                                            `${key}: ${String(value)}`,
                                                    )
                                                    .join(', ')}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination page={logs} />
            </div>
        </>
    );
}

ActivityIndex.layout = {
    breadcrumbs: [{ title: 'Activity', href: activity() }],
};
