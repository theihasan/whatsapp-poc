import { router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import ConversationMessageController from '@/actions/App/Http/Controllers/Conversations/ConversationMessageController';
import MessageStatusIcon from '@/components/inbox/message-status-icon';
import { Button } from '@/components/ui/button';
import { formatBytes, formatTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Message } from '@/types';

export default function MessageBubble({ message }: { message: Message }) {
    const outgoing = message.direction === 'outgoing';
    const retryable =
        outgoing &&
        (message.status === 'failed' || message.status === 'unknown');

    return (
        <div className={cn('flex', outgoing ? 'justify-end' : 'justify-start')}>
            <div
                className={cn(
                    'max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-xs sm:max-w-[65%]',
                    outgoing
                        ? 'rounded-br-md bg-emerald-50 text-emerald-950 dark:bg-emerald-950/60 dark:text-emerald-50'
                        : 'rounded-bl-md bg-muted',
                )}
            >
                {message.attachments.map((attachment) =>
                    attachment.mime_type.startsWith('image/') ? (
                        <a
                            key={attachment.id}
                            href={attachment.url}
                            target="_blank"
                            rel="noreferrer"
                            className="mb-1 block"
                        >
                            <img
                                src={attachment.url}
                                alt={attachment.name ?? 'Image attachment'}
                                className="max-h-64 rounded-lg object-cover"
                                loading="lazy"
                            />
                        </a>
                    ) : (
                        <a
                            key={attachment.id}
                            href={attachment.url}
                            className="mb-1 flex items-center gap-2 rounded-lg border bg-background/60 p-2 hover:bg-background"
                        >
                            <FileText className="size-4 shrink-0" />
                            <span className="min-w-0 truncate">
                                {attachment.name ?? 'Document'}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {formatBytes(attachment.size)}
                            </span>
                        </a>
                    ),
                )}

                {message.group_sender && (
                    <p
                        className="mb-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                        title={message.group_sender.phone_number ?? undefined}
                    >
                        {message.group_sender.name}
                    </p>
                )}

                {message.media_error && (
                    <p className="mb-1 text-xs text-muted-foreground italic">
                        {message.media_error}
                    </p>
                )}

                {message.type === 'unsupported' ? (
                    <p className="text-muted-foreground italic">
                        This message type isn't supported yet.
                    </p>
                ) : (
                    message.body && (
                        <p className="break-words whitespace-pre-wrap">
                            {message.body}
                        </p>
                    )
                )}

                <div className="mt-1 flex items-center justify-end gap-1 text-[11px] text-muted-foreground">
                    {message.is_automated && <span>Automated ·</span>}
                    {outgoing && message.sender_name && (
                        <span>{message.sender_name} ·</span>
                    )}
                    <time dateTime={message.created_at ?? undefined}>
                        {formatTime(message.created_at)}
                    </time>
                    {outgoing && <MessageStatusIcon status={message.status} />}
                </div>

                {retryable && (
                    <div className="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-current/10 pt-2">
                        <p className="text-xs text-red-700 dark:text-red-300">
                            {message.failure_reason ??
                                (message.status === 'unknown'
                                    ? 'Delivery could not be confirmed.'
                                    : 'Not sent.')}
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-7"
                            onClick={() =>
                                router.post(
                                    ConversationMessageController.retry.url(
                                        message.id,
                                    ),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Retry
                        </Button>
                    </div>
                )}
                {outgoing &&
                    message.status === 'queued' &&
                    message.failure_reason && (
                        <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                            {message.failure_reason}
                        </p>
                    )}
            </div>
        </div>
    );
}
