import { useHttp } from '@inertiajs/react';
import { ArrowDown } from 'lucide-react';
import {
    Fragment,
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import ConversationMessageController from '@/actions/App/Http/Controllers/Conversations/ConversationMessageController';
import MessageBubble from '@/components/inbox/message-bubble';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { dayKey, formatDayLabel } from '@/lib/format';
import type { Message } from '@/types';

const NEAR_BOTTOM_PX = 120;

/**
 * Chronological message list. Keeps the view pinned to the newest message only while
 * the user is already near the bottom; otherwise offers a "new messages" jump button.
 */
export default function MessageThread({
    conversationId,
    messages,
    hasOlder,
    onOlderLoaded,
}: {
    conversationId: string;
    messages: Message[];
    hasOlder: boolean;
    onOlderLoaded: (older: Message[], hasMore: boolean) => void;
}) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const pinnedToBottom = useRef(true);
    const previousHeight = useRef<number | null>(null);
    const lastMessageId = useRef<string | null>(null);
    const [unseen, setUnseen] = useState(0);
    const [olderError, setOlderError] = useState<string | null>(null);
    const olderRequest = useHttp<
        Record<string, never>,
        { messages: Message[]; has_older: boolean }
    >({});

    const scrollToBottom = useCallback((behavior: ScrollBehavior = 'auto') => {
        const element = scrollRef.current;

        if (element) {
            element.scrollTo({ top: element.scrollHeight, behavior });
        }

        setUnseen(0);
    }, []);

    useLayoutEffect(() => {
        pinnedToBottom.current = true;
        lastMessageId.current = null;
        scrollToBottom();
    }, [conversationId, scrollToBottom]);

    useLayoutEffect(() => {
        const element = scrollRef.current;
        const newest = messages.at(-1)?.id ?? null;

        if (!element) {
            return;
        }

        if (previousHeight.current !== null) {
            element.scrollTop += element.scrollHeight - previousHeight.current;
            previousHeight.current = null;
        } else if (newest !== lastMessageId.current) {
            if (
                pinnedToBottom.current ||
                messages.at(-1)?.direction === 'outgoing'
            ) {
                scrollToBottom('smooth');
            } else if (lastMessageId.current !== null) {
                setUnseen((count) => count + 1);
            }
        }

        lastMessageId.current = newest;
    }, [messages, scrollToBottom]);

    useEffect(() => setOlderError(null), [conversationId]);

    const loadOlder = async () => {
        const oldest = messages[0];

        if (!oldest || olderRequest.processing) {
            return;
        }

        try {
            const response = await olderRequest.get(
                ConversationMessageController.index.url(conversationId, {
                    query: { before: oldest.id },
                }),
            );
            previousHeight.current = scrollRef.current?.scrollHeight ?? null;
            onOlderLoaded(response.messages, response.has_older);
        } catch {
            setOlderError('Could not load older messages.');
        }
    };

    return (
        <div className="relative min-h-0 flex-1">
            <div
                ref={scrollRef}
                className="h-full space-y-2 overflow-y-auto px-4 py-4"
                onScroll={(event) => {
                    const element = event.currentTarget;
                    pinnedToBottom.current =
                        element.scrollHeight -
                            element.scrollTop -
                            element.clientHeight <
                        NEAR_BOTTOM_PX;

                    if (pinnedToBottom.current) {
                        setUnseen(0);
                    }
                }}
                aria-live="polite"
            >
                {hasOlder && (
                    <div className="flex justify-center pb-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => void loadOlder()}
                            disabled={olderRequest.processing}
                        >
                            {olderRequest.processing ? (
                                <Spinner />
                            ) : (
                                'Load earlier messages'
                            )}
                        </Button>
                    </div>
                )}
                {olderError && (
                    <p className="text-center text-xs text-red-600">
                        {olderError}
                    </p>
                )}

                {messages.length === 0 && (
                    <p className="py-16 text-center text-sm text-muted-foreground">
                        No messages yet. Say hello.
                    </p>
                )}

                {messages.map((message, index) => {
                    const previous = messages[index - 1];
                    const showDay =
                        !previous ||
                        dayKey(previous.created_at) !==
                            dayKey(message.created_at);

                    return (
                        <Fragment key={message.id}>
                            {showDay && message.created_at && (
                                <div className="flex justify-center py-2">
                                    <span className="rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground">
                                        {formatDayLabel(message.created_at)}
                                    </span>
                                </div>
                            )}
                            <MessageBubble message={message} />
                        </Fragment>
                    );
                })}
            </div>

            {unseen > 0 && (
                <Button
                    size="sm"
                    className="absolute bottom-4 left-1/2 -translate-x-1/2 shadow-md"
                    onClick={() => scrollToBottom('smooth')}
                >
                    <ArrowDown />
                    {unseen} new {unseen === 1 ? 'message' : 'messages'}
                </Button>
            )}
        </div>
    );
}
