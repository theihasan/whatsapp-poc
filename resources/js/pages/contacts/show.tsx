import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import ConversationController from '@/actions/App/Http/Controllers/Conversations/ConversationController';
import ConsentSection from '@/components/contacts/consent-section';
import ContactAvatar from '@/components/contacts/contact-avatar';
import ContactFormFields from '@/components/contacts/contact-form-fields';
import type { ContactFormData } from '@/components/contacts/contact-form-fields';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatListTimestamp } from '@/lib/format';
import { show as showConversation } from '@/routes/inbox';
import type {
    ContactActivity,
    ContactConversation,
    ContactDetails,
} from '@/types';

export default function ContactShow({
    contact,
    conversations,
    activity,
    devices,
}: {
    contact: ContactDetails;
    conversations: ContactConversation[];
    activity: ContactActivity;
    devices: { id: string; name: string }[];
}) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Contacts', href: ContactController.index() },
            {
                title: contact.display_name,
                href: ContactController.show(contact.id),
            },
        ],
    });

    const form = useForm<ContactFormData>({
        name: contact.name ?? '',
        phone_number: contact.phone_number ?? '',
        notes: contact.notes ?? '',
        tags: contact.tags?.map((tag) => tag.name) ?? [],
    });

    const startConversation = (deviceId: string) =>
        router.post(ConversationController.store.url(), {
            contact_id: contact.id,
            whatsapp_account_id: deviceId,
        });

    return (
        <>
            <Head title={contact.display_name} />
            <div className="mx-auto grid w-full max-w-5xl gap-10 p-4 md:p-6 lg:grid-cols-[1fr_18rem]">
                <div className="space-y-10">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <ContactAvatar
                                name={contact.display_name}
                                avatarUrl={contact.avatar_url}
                                className="size-14"
                            />
                            <Heading
                                title={contact.display_name}
                                description={
                                    contact.source === 'manual'
                                        ? 'Added manually'
                                        : 'Created from an incoming message'
                                }
                            />
                        </div>
                        {devices.length > 0 && contact.wa_jid && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button>Message</Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {devices.map((device) => (
                                        <DropdownMenuItem
                                            key={device.id}
                                            onSelect={() =>
                                                startConversation(device.id)
                                            }
                                        >
                                            From {device.name}
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) =>
                                contact.phone_number
                                    ? data
                                    : {
                                          ...data,
                                          phone_number:
                                              data.phone_number || undefined,
                                      },
                            );
                            form.patch(
                                ContactController.update.url(contact.id),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <ContactFormFields
                            data={form.data}
                            errors={form.errors}
                            setData={form.setData}
                        />
                        <div className="flex justify-between gap-2">
                            <Button disabled={form.processing || !form.isDirty}>
                                Save contact
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-red-600"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Delete this contact and all of its conversations?',
                                        )
                                    ) {
                                        router.delete(
                                            ContactController.destroy.url(
                                                contact.id,
                                            ),
                                        );
                                    }
                                }}
                            >
                                Delete
                            </Button>
                        </div>
                    </form>

                    <ConsentSection
                        contactId={contact.id}
                        consents={contact.consents ?? []}
                    />
                </div>

                <aside className="space-y-8 text-sm">
                    <section className="space-y-2">
                        <h2 className="font-medium">Conversations</h2>
                        {conversations.length === 0 ? (
                            <p className="text-muted-foreground">None yet.</p>
                        ) : (
                            <ul className="space-y-2">
                                {conversations.map((conversation) => (
                                    <li key={conversation.id}>
                                        <Link
                                            href={showConversation.url(
                                                conversation.id,
                                            )}
                                            className="block rounded-lg border p-3 hover:bg-accent/50"
                                        >
                                            <p className="font-medium">
                                                {conversation.device}
                                            </p>
                                            <p className="truncate text-muted-foreground">
                                                {conversation.last_message_preview ??
                                                    '—'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {conversation.status ===
                                                'closed'
                                                    ? 'Closed · '
                                                    : ''}
                                                {formatListTimestamp(
                                                    conversation.last_message_at,
                                                )}
                                            </p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="space-y-2">
                        <h2 className="font-medium">Activity</h2>
                        <p className="text-muted-foreground">
                            {activity.messages_in} received ·{' '}
                            {activity.messages_out} sent
                        </p>
                        <ul className="space-y-1 text-xs text-muted-foreground">
                            {activity.events.map((event) => (
                                <li key={event.id}>
                                    {event.action
                                        .replace('contact.', '')
                                        .replaceAll('_', ' ')}
                                    {event.user ? ` by ${event.user}` : ''} ·{' '}
                                    {formatListTimestamp(event.created_at)}
                                </li>
                            ))}
                        </ul>
                    </section>
                </aside>
            </div>
        </>
    );
}
