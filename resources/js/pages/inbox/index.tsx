import { Head, Link } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { inbox } from '@/routes';
import DeviceStatusBadge from '@/components/devices/device-status-badge';
import Composer from '@/components/inbox/composer';
import ContactPanel from '@/components/inbox/contact-panel';
import ConversationList from '@/components/inbox/conversation-list';
import MessageThread from '@/components/inbox/message-thread';
import {
    useConversationListUpdates,
    useConversationMessages,
    useRealtimeStatus,
} from '@/hooks/use-inbox-realtime';
import { cn } from '@/lib/utils';
import type {
    ActiveConversation,
    Agent,
    ConversationSummary,
    InboxAccount,
    InboxFilters,
    Message,
} from '@/types';

type Props = {
    filters: InboxFilters;
    accounts: InboxAccount[];
    conversations: ConversationSummary[];
    agents: Agent[];
    active: ActiveConversation | null;
};

function mergeMessages(current: Message[], incoming: Message[]): Message[] {
    const byId = new Map(current.map((message) => [message.id, message]));

    for (const message of incoming) {
        byId.set(message.id, message);
    }

    return [...byId.values()].sort((a, b) =>
        a.id < b.id ? -1 : a.id > b.id ? 1 : 0,
    );
}

function ActiveThread({
    active,
    agents,
}: {
    active: ActiveConversation;
    agents: Agent[];
}) {
    const conversationId = active.conversation.id;
    const [messages, setMessages] = useState(active.messages);
    const [hasOlder, setHasOlder] = useState(active.has_older);

    useEffect(() => {
        setMessages((current) => mergeMessages(current, active.messages));
    }, [active.messages]);

    const onMessage = useCallback(
        (message: Message) =>
            setMessages((current) => mergeMessages(current, [message])),
        [],
    );
    useConversationMessages(conversationId, onMessage);

    const disabledReason =
        active.device.status === 'connected'
            ? null
            : `${active.device.name} is not connected. Messages can be sent once it reconnects.`;

    return (
        <>
            <section className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center justify-between gap-3 border-b px-4 py-3">
                    <div className="min-w-0">
                        <h1 className="truncate font-semibold">
                            {active.contact.display_name}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {active.contact.phone_number ??
                                active.contact.wa_jid}{' '}
                            · via {active.device.name}
                        </p>
                    </div>
                    <DeviceStatusBadge status={active.device.status} />
                </header>
                <MessageThread
                    conversationId={conversationId}
                    messages={messages}
                    hasOlder={hasOlder}
                    onOlderLoaded={(older, more) => {
                        setMessages((current) => mergeMessages(current, older));
                        setHasOlder(more);
                    }}
                />
                <Composer
                    conversationId={conversationId}
                    disabledReason={disabledReason}
                />
            </section>
            <ContactPanel active={active} agents={agents} />
        </>
    );
}

export default function Inbox({
    filters,
    accounts,
    conversations,
    agents,
    active,
}: Props) {
    useConversationListUpdates();
    const realtime = useRealtimeStatus();

    return (
        <>
            <Head title={active ? active.contact.display_name : 'Inbox'} />
            {realtime !== 'connected' && (
                <div
                    role="status"
                    className="border-b bg-amber-50 px-4 py-1.5 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                >
                    {realtime === 'connecting'
                        ? 'Connecting to live updates…'
                        : 'Live updates are offline. New messages appear after you refresh.'}
                </div>
            )}
            <div className="flex h-[calc(100svh-4rem)] min-h-0 md:h-[calc(100svh-5rem)]">
                <div
                    className={cn(
                        'min-h-0 md:flex',
                        active ? 'hidden' : 'flex w-full md:w-auto',
                    )}
                >
                    <ConversationList
                        conversations={conversations}
                        accounts={accounts}
                        filters={filters}
                        activeId={active?.conversation.id ?? null}
                    />
                </div>

                {active ? (
                    <div className="flex min-w-0 flex-1 flex-col md:flex-row">
                        <Link
                            href={inbox.url()}
                            className="border-b px-4 py-2 text-sm text-muted-foreground md:hidden"
                        >
                            ← All conversations
                        </Link>
                        <ActiveThread
                            key={active.conversation.id}
                            active={active}
                            agents={agents}
                        />
                    </div>
                ) : (
                    <div className="hidden flex-1 flex-col items-center justify-center gap-2 text-center text-muted-foreground md:flex">
                        <MessageSquare className="size-8" />
                        <p className="text-sm">Select a conversation</p>
                    </div>
                )}
            </div>
        </>
    );
}

Inbox.layout = {
    breadcrumbs: [{ title: 'Inbox', href: inbox.url() }],
};
