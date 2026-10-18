import { useForm } from '@inertiajs/react';
import { Paperclip, SendHorizontal, Smile, X } from 'lucide-react';
import { useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import ConversationMessageController from '@/actions/App/Http/Controllers/Conversations/ConversationMessageController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { formatBytes } from '@/lib/format';

const EMOJI = [
    '😀',
    '😂',
    '😊',
    '😍',
    '🙏',
    '👍',
    '👏',
    '🎉',
    '❤️',
    '🔥',
    '✅',
    '👋',
    '🙌',
    '🤝',
    '😅',
    '😢',
    '🤔',
    '👀',
    '📞',
    '📦',
    '🕒',
    '📍',
    '💬',
    '⭐',
];
const ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt';

export default function Composer({
    conversationId,
    disabledReason,
    suggestion,
    toolbar,
}: {
    conversationId: string;
    disabledReason: string | null;
    suggestion?: string | null;
    toolbar?: (insert: (text: string) => void) => ReactNode;
}) {
    const textarea = useRef<HTMLTextAreaElement>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{ body: string; attachment: File | null }>({
        body: '',
        attachment: null,
    });
    const disabled = disabledReason !== null || form.processing;

    const insert = (text: string) => {
        const element = textarea.current;
        const start = element?.selectionStart ?? form.data.body.length;
        const end = element?.selectionEnd ?? form.data.body.length;
        const next =
            form.data.body.slice(0, start) + text + form.data.body.slice(end);

        form.setData('body', next);
        requestAnimationFrame(() => {
            element?.focus();
            element?.setSelectionRange(
                start + text.length,
                start + text.length,
            );
        });
    };

    const submit = () => {
        if (
            disabled ||
            (form.data.body.trim() === '' && form.data.attachment === null)
        ) {
            return;
        }

        form.post(ConversationMessageController.store.url(conversationId), {
            forceFormData: form.data.attachment !== null,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.reset();

                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.nativeEvent.isComposing
        ) {
            event.preventDefault();
            submit();
        }
    };

    return (
        <div className="border-t p-3">
            {suggestion && form.data.body === '' && (
                <div className="mb-2 flex items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-sm">
                    <p className="min-w-0 flex-1">
                        <span className="text-xs font-medium text-muted-foreground">
                            Suggested reply ·{' '}
                        </span>
                        {suggestion}
                    </p>
                    <Button
                        size="sm"
                        variant="outline"
                        className="h-7"
                        onClick={() => insert(suggestion)}
                    >
                        Use
                    </Button>
                </div>
            )}
            {disabledReason && (
                <p className="mb-2 text-xs text-amber-700 dark:text-amber-400">
                    {disabledReason}
                </p>
            )}

            {form.data.attachment && (
                <div className="mb-2 flex items-center gap-2 rounded-md border px-2 py-1 text-sm">
                    <Paperclip className="size-4" />
                    <span className="min-w-0 flex-1 truncate">
                        {form.data.attachment.name}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {formatBytes(form.data.attachment.size)}
                    </span>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        aria-label="Remove attachment"
                        onClick={() => {
                            form.setData('attachment', null);

                            if (fileInput.current) {
                                fileInput.current.value = '';
                            }
                        }}
                    >
                        <X />
                    </Button>
                </div>
            )}

            <div className="flex items-end gap-2">
                <div className="flex items-center">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Insert emoji"
                                disabled={disabled}
                            >
                                <Smile />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            className="grid w-64 grid-cols-8 gap-1 p-2"
                        >
                            {EMOJI.map((emoji) => (
                                <button
                                    key={emoji}
                                    type="button"
                                    className="rounded p-1 text-lg hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    onClick={() => insert(emoji)}
                                    aria-label={`Insert ${emoji}`}
                                >
                                    {emoji}
                                </button>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Attach a file"
                        disabled={disabled}
                        onClick={() => fileInput.current?.click()}
                    >
                        <Paperclip />
                    </Button>
                    <input
                        ref={fileInput}
                        type="file"
                        accept={ACCEPT}
                        className="hidden"
                        onChange={(event) =>
                            form.setData(
                                'attachment',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                    {toolbar?.(insert)}
                </div>

                <label htmlFor="composer-body" className="sr-only">
                    Message
                </label>
                <Textarea
                    id="composer-body"
                    ref={textarea}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    onKeyDown={onKeyDown}
                    placeholder={
                        disabledReason
                            ? 'Sending is unavailable'
                            : 'Write a message…'
                    }
                    rows={1}
                    maxLength={4096}
                    disabled={disabledReason !== null}
                    className="max-h-40 min-h-10 flex-1 resize-none"
                />
                <Button
                    onClick={submit}
                    disabled={disabled}
                    aria-label="Send message"
                    size="icon"
                >
                    <SendHorizontal />
                </Button>
            </div>
            <InputError
                message={form.errors.body ?? form.errors.attachment}
                className="mt-1"
            />
        </div>
    );
}
