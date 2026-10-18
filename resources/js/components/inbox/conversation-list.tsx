import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { inbox } from '@/routes';
import { show as showConversation } from '@/routes/inbox';
import ContactAvatar from '@/components/contacts/contact-avatar';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatListTimestamp } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ConversationSummary, InboxAccount, InboxFilters } from '@/types';

const FILTERS: { value: InboxFilters['filter']; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'unread', label: 'Unread' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
    { value: 'mine', label: 'Mine' },
    { value: 'direct', label: 'Direct' },
    { value: 'groups', label: 'Groups' },
];

function query(filters: InboxFilters): Record<string, string> {
    return Object.fromEntries(
        Object.entries({
            account: filters.account ?? '',
            filter: filters.filter === 'all' ? '' : filters.filter,
            search: filters.search,
        }).filter(([, value]) => value !== ''),
    );
}

export default function ConversationList({
    conversations,
    accounts,
    filters,
    activeId,
}: {
    conversations: ConversationSummary[];
    accounts: InboxAccount[];
    filters: InboxFilters;
    activeId: string | null;
}) {
    const [search, setSearch] = useState(filters.search);
    const firstRender = useRef(true);

    const visit = (next: InboxFilters) => {
        router.get(
            activeId ? showConversation.url(activeId) : inbox.url(),
            query(next),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['conversations', 'filters'],
            },
        );
    };

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = window.setTimeout(
            () => visit({ ...filters, search }),
            300,
        );

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <aside className="flex min-h-0 w-full flex-col border-r md:w-80 lg:w-96">
            <div className="space-y-3 border-b p-3">
                {accounts.length > 1 && (
                    <Select
                        value={filters.account ?? 'all'}
                        onValueChange={(value) =>
                            visit({
                                ...filters,
                                account: value === 'all' ? null : value,
                            })
                        }
                    >
                        <SelectTrigger aria-label="WhatsApp account">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All devices</SelectItem>
                            {accounts.map((account) => (
                                <SelectItem key={account.id} value={account.id}>
                                    {account.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search name or number"
                        aria-label="Search conversations"
                        className="pl-8"
                    />
                </div>
                <div
                    role="tablist"
                    aria-label="Filter conversations"
                    className="flex gap-1 overflow-x-auto"
                >
                    {FILTERS.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            role="tab"
                            aria-selected={filters.filter === option.value}
                            onClick={() =>
                                visit({ ...filters, filter: option.value })
                            }
                            className={cn(
                                'rounded-full px-3 py-1 text-xs font-medium transition-colors',
                                filters.filter === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-accent',
                            )}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </div>

            <nav
                aria-label="Conversations"
                className="min-h-0 flex-1 overflow-y-auto"
            >
                {conversations.length === 0 ? (
                    <p className="p-6 text-center text-sm text-muted-foreground">
                        {filters.search || filters.filter !== 'all'
                            ? 'No conversations match these filters.'
                            : 'No conversations yet. Incoming messages appear here.'}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {conversations.map((conversation) => (
                            <li key={conversation.id}>
                                <Link
                                    href={showConversation.url(
                                        conversation.id,
                                        { query: query(filters) },
                                    )}
                                    preserveScroll
                                    className={cn(
                                        'flex gap-3 px-3 py-3 hover:bg-accent/60',
                                        conversation.id === activeId &&
                                            'bg-accent',
                                    )}
                                >
                                    <ContactAvatar
                                        name={conversation.contact.name}
                                        avatarUrl={
                                            conversation.contact.avatar_url
                                        }
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span
                                                className={cn(
                                                    'truncate',
                                                    conversation.unread_count >
                                                        0
                                                        ? 'font-semibold'
                                                        : 'font-medium',
                                                )}
                                            >
                                                {conversation.contact.name}
                                            </span>
                                            <time
                                                className="shrink-0 text-xs text-muted-foreground"
                                                dateTime={
                                                    conversation.last_message_at ??
                                                    undefined
                                                }
                                            >
                                                {formatListTimestamp(
                                                    conversation.last_message_at,
                                                )}
                                            </time>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="truncate text-sm text-muted-foreground">
                                                {conversation.last_message_preview ??
                                                    conversation.contact
                                                        .phone_number ??
                                                    ''}
                                            </p>
                                            {conversation.unread_count > 0 && (
                                                <span
                                                    className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white"
                                                    aria-label={`${conversation.unread_count} unread`}
                                                >
                                                    {conversation.unread_count}
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-0.5 flex gap-2 text-[11px] text-muted-foreground">
                                            {conversation.status ===
                                                'closed' && <span>Closed</span>}
                                            {conversation.assignee && (
                                                <span>
                                                    →{' '}
                                                    {conversation.assignee.name}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </nav>
        </aside>
    );
}
