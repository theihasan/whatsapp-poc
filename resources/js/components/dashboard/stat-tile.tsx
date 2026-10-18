import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export default function StatTile({
    label,
    value,
    detail,
    href,
    tone = 'default',
}: {
    label: string;
    value: number | string;
    detail?: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    tone?: 'default' | 'attention';
}) {
    const body = (
        <>
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'mt-1 text-3xl font-semibold tabular-nums',
                    tone === 'attention' && 'text-red-600 dark:text-red-400',
                )}
            >
                {value}
            </p>
            {detail && (
                <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
            )}
        </>
    );

    return href ? (
        <Link
            href={href}
            className="rounded-xl border p-4 transition-colors hover:bg-accent/40"
        >
            {body}
        </Link>
    ) : (
        <div className="rounded-xl border p-4">{body}</div>
    );
}
