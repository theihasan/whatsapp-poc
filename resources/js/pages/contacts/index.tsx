import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import ContactAvatar from '@/components/contacts/contact-avatar';
import ContactFormFields from '@/components/contacts/contact-form-fields';
import type { ContactFormData } from '@/components/contacts/contact-form-fields';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatListTimestamp } from '@/lib/format';
import type { ContactListItem, Paginated } from '@/types';

type Filters = {
    search: string;
    tag: string | null;
    consent: 'opted_in' | 'none' | null;
};

function CreateContactDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm<ContactFormData>({
        name: '',
        phone_number: '',
        notes: '',
        tags: [],
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    New contact
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New contact</DialogTitle>
                    <DialogDescription>
                        Adding a contact does not record consent. Record opt-in
                        separately with its evidence.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(ContactController.store.url(), {
                            onSuccess: () => setOpen(false),
                        });
                    }}
                    className="space-y-4"
                >
                    <ContactFormFields
                        data={form.data}
                        errors={form.errors}
                        setData={form.setData}
                    />
                    <DialogFooter>
                        <Button disabled={form.processing}>
                            Create contact
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ContactsIndex({
    contacts,
    tags,
    filters,
}: {
    contacts: Paginated<ContactListItem>;
    tags: string[];
    filters: Filters;
}) {
    const [search, setSearch] = useState(filters.search);
    const firstRender = useRef(true);

    const visit = (next: Filters) =>
        router.get(
            ContactController.index.url(),
            Object.fromEntries(
                Object.entries(next).filter(
                    ([, value]) => value !== null && value !== '',
                ),
            ),
            { preserveState: true, replace: true },
        );

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
        <>
            <Head title="Contacts" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Contacts"
                        description="People who message your devices or that you added manually."
                    />
                    <CreateContactDialog />
                </div>

                <div className="flex flex-wrap gap-2">
                    <div className="relative min-w-56 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name or number"
                            aria-label="Search contacts"
                            className="pl-8"
                        />
                    </div>
                    <Select
                        value={filters.tag ?? 'all'}
                        onValueChange={(tag) =>
                            visit({
                                ...filters,
                                tag: tag === 'all' ? null : tag,
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-40"
                            aria-label="Filter by tag"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All tags</SelectItem>
                            {tags.map((tag) => (
                                <SelectItem key={tag} value={tag}>
                                    {tag}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.consent ?? 'all'}
                        onValueChange={(consent) =>
                            visit({
                                ...filters,
                                consent:
                                    consent === 'all'
                                        ? null
                                        : (consent as Filters['consent']),
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-44"
                            aria-label="Filter by consent"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Any consent</SelectItem>
                            <SelectItem value="opted_in">Opted in</SelectItem>
                            <SelectItem value="none">No opt-in</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {contacts.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No contacts match.
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium sm:table-cell">
                                        Number
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium md:table-cell">
                                        Tags
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Consent
                                    </th>
                                    <th className="hidden px-4 py-2 font-medium lg:table-cell">
                                        Last activity
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {contacts.data.map((contact) => (
                                    <tr
                                        key={contact.id}
                                        className="hover:bg-accent/40"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                <ContactAvatar
                                                    name={contact.display_name}
                                                    avatarUrl={
                                                        contact.avatar_url
                                                    }
                                                    className="size-8"
                                                />
                                                <Link
                                                    href={ContactController.show.url(
                                                        contact.id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {contact.display_name}
                                                </Link>
                                            </div>
                                        </td>
                                        <td className="hidden px-4 py-3 text-muted-foreground sm:table-cell">
                                            {contact.phone_number ?? '—'}
                                        </td>
                                        <td className="hidden px-4 py-3 md:table-cell">
                                            <div className="flex flex-wrap gap-1">
                                                {contact.tags?.map((tag) => (
                                                    <Badge
                                                        key={tag.id}
                                                        variant="secondary"
                                                    >
                                                        {tag.name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {contact.has_consent ? (
                                                <span className="text-emerald-700 dark:text-emerald-400">
                                                    Opted in
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                        </td>
                                        <td className="hidden px-4 py-3 text-muted-foreground lg:table-cell">
                                            {formatListTimestamp(
                                                contact.last_activity_at,
                                            ) || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination page={contacts} />
            </div>
        </>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [{ title: 'Contacts', href: ContactController.index() }],
};
