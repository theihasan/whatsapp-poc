import { router, useHttp } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';
import MarkConversationReadController from '@/actions/App/Http/Controllers/Conversations/MarkConversationReadController';
import type { ConversationSummary, Message } from '@/types';

/**
 * Inbox WebSocket subscriptions. Each hook owns exactly one channel listener and Echo
 * releases it on unmount or when the conversation changes.
 */
export function useConversationListUpdates(): void {
    const pending = useRef<number | null>(null);

    useEcho<{ conversation: ConversationSummary }>(
        'conversations',
        'ConversationUpdated',
        () => {
            if (pending.current !== null) {
                return;
            }

            pending.current = window.setTimeout(() => {
                pending.current = null;
                router.reload({ only: ['conversations'] });
            }, 250);
        },
    );

    useEffect(
        () => () => {
            if (pending.current !== null) {
                window.clearTimeout(pending.current);
            }
        },
        [],
    );
}

export function useConversationMessages(
    conversationId: string,
    onMessage: (message: Message) => void,
): void {
    const markRead = useHttp({});

    useEcho<{ message: Message }>(
        `conversations.${conversationId}`,
        ['MessageCreated', 'MessageStatusUpdated'],
        ({ message }) => {
            onMessage(message);

            if (
                message.direction === 'incoming' &&
                document.visibilityState === 'visible'
            ) {
                void markRead
                    .post(MarkConversationReadController.url(conversationId))
                    .catch(() => undefined);
            }
        },
        [conversationId, onMessage],
    );
}

export function useRealtimeStatus(): 'connected' | 'connecting' | 'offline' {
    const status = useConnectionStatus();

    if (status === 'connected') {
        return 'connected';
    }

    return status === 'connecting' || status === 'reconnecting'
        ? 'connecting'
        : 'offline';
}
