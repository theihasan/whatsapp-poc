import { Link, router, useForm } from '@inertiajs/react';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import ConversationController from '@/actions/App/Http/Controllers/Conversations/ConversationController';
import ContactAvatar from '@/components/contacts/contact-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatListTimestamp } from '@/lib/format';
import type { ActiveConversation, Agent } from '@/types';

export default function ContactPanel({
    active,
    agents,
}: {
    active: ActiveConversation;
    agents: Agent[];
}) {
    const { contact, conversation } = active;
    const notes = useForm({
        name: contact.name ?? contact.display_name,
        notes: contact.notes ?? '',
    });
    const update = (changes: Record<string, string | number | null>) =>
        router.patch(
            ConversationController.update.url(conversation.id),
            changes,
            { preserveScroll: true, preserveState: true },
        );
    const activeConsents = (contact.consents ?? []).filter(
        (consent) => consent.revoked_at === null,
    );

    return (
        <aside className="hidden w-72 shrink-0 flex-col gap-6 overflow-y-auto border-l p-4 text-sm xl:flex">
            <section className="space-y-1">
                <ContactAvatar
                    name={contact.display_name}
                    avatarUrl={contact.avatar_url}
                    className="mb-2 size-16"
                />
                <h2 className="text-base font-semibold">
                    {contact.display_name}
                </h2>
                {contact.phone_number && (
                    <p className="text-muted-foreground">
                        {contact.phone_number}
                    </p>
                )}
                {!contact.phone_number && (
                    <p className="text-xs text-muted-foreground">
                        Phone number not shared by WhatsApp
                    </p>
                )}
                <Link
                    href={ContactController.show.url(contact.id)}
                    className="text-xs underline underline-offset-4"
                >
                    Open contact
                </Link>
            </section>

            <section className="space-y-3">
                <div className="grid gap-1.5">
                    <Label htmlFor="conversation-status">Status</Label>
                    <Select
                        value={conversation.status}
                        onValueChange={(status) => update({ status })}
                    >
                        <SelectTrigger id="conversation-status">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="conversation-assignee">Assigned to</Label>
                    <Select
                        value={
                            conversation.assignee
                                ? String(conversation.assignee.id)
                                : 'none'
                        }
                        onValueChange={(value) =>
                            update({
                                assigned_user_id:
                                    value === 'none' ? null : Number(value),
                            })
                        }
                    >
                        <SelectTrigger id="conversation-assignee">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Unassigned</SelectItem>
                            {agents.map((agent) => (
                                <SelectItem
                                    key={agent.id}
                                    value={String(agent.id)}
                                >
                                    {agent.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </section>

            <section className="space-y-2">
                <h3 className="font-medium">Tags</h3>
                {contact.tags && contact.tags.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                        {contact.tags.map((tag) => (
                            <Badge key={tag.id} variant="secondary">
                                {tag.name}
                            </Badge>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted-foreground">No tags</p>
                )}
            </section>

            <section className="space-y-2">
                <h3 className="font-medium">Consent</h3>
                {activeConsents.length > 0 ? (
                    <ul className="space-y-1">
                        {activeConsents.map((consent) => (
                            <li key={consent.id}>
                                <span className="font-medium capitalize">
                                    {consent.scope}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    · via {consent.source}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground">No recorded opt-in</p>
                )}
            </section>

            <form
                className="space-y-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    notes.patch(ContactController.update.url(contact.id), {
                        preserveScroll: true,
                        preserveState: true,
                    });
                }}
            >
                <Label htmlFor="contact-notes-inline" className="font-medium">
                    Notes
                </Label>
                <Textarea
                    id="contact-notes-inline"
                    value={notes.data.notes}
                    onChange={(event) =>
                        notes.setData('notes', event.target.value)
                    }
                    placeholder="Add a private note"
                    rows={3}
                    maxLength={5000}
                />
                {notes.isDirty && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={notes.processing}
                    >
                        Save note
                    </Button>
                )}
            </form>

            <section className="space-y-1 text-xs text-muted-foreground">
                <p>Device: {active.device.name}</p>
                {contact.last_activity_at && (
                    <p>
                        Last activity:{' '}
                        {formatListTimestamp(contact.last_activity_at)}
                    </p>
                )}
                <p>
                    Source:{' '}
                    {contact.source === 'manual'
                        ? 'Added manually'
                        : 'First message'}
                </p>
            </section>
        </aside>
    );
}
