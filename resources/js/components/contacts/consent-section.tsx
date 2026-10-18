import { router, useForm } from '@inertiajs/react';
import ContactConsentController from '@/actions/App/Http/Controllers/Contacts/ContactConsentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatListTimestamp } from '@/lib/format';
import type { ContactConsent } from '@/types';

const SOURCES: Record<string, string> = {
    web_form: 'Web form',
    in_person: 'In person',
    whatsapp_message: 'WhatsApp message',
    phone_call: 'Phone call',
    email: 'Email',
    other: 'Other',
};

export default function ConsentSection({
    contactId,
    consents,
}: {
    contactId: string;
    consents: ContactConsent[];
}) {
    const form = useForm({
        scope: 'marketing',
        source: 'web_form',
        evidence: '',
        consented_at: new Date().toISOString().slice(0, 16),
    });

    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-base font-medium">Consent</h2>
                <p className="text-sm text-muted-foreground">
                    Record explicit opt-ins with evidence. A contact who replies
                    STOP is opted out automatically.
                </p>
            </div>

            {consents.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No consent recorded. This contact must not receive
                    promotional messages.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border text-sm">
                    {consents.map((consent) => (
                        <li
                            key={consent.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <div>
                                <p>
                                    <span className="font-medium capitalize">
                                        {consent.scope}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ·{' '}
                                        {SOURCES[consent.source] ??
                                            consent.source}
                                    </span>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {consent.evidence} ·{' '}
                                    {formatListTimestamp(consent.consented_at)}
                                    {consent.revoked_at &&
                                        ` · revoked ${formatListTimestamp(consent.revoked_at)}`}
                                </p>
                            </div>
                            {consent.revoked_at === null ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(
                                            ContactConsentController.destroy.url(
                                                {
                                                    contact: contactId,
                                                    consent: consent.id,
                                                },
                                            ),
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Revoke
                                </Button>
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    Revoked
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <form
                className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(ContactConsentController.store.url(contactId), {
                        preserveScroll: true,
                        onSuccess: () => form.reset('evidence'),
                    });
                }}
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="consent-scope">Scope</Label>
                    <Select
                        value={form.data.scope}
                        onValueChange={(value) => form.setData('scope', value)}
                    >
                        <SelectTrigger id="consent-scope">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="marketing">Marketing</SelectItem>
                            <SelectItem value="service">
                                Service updates
                            </SelectItem>
                            <SelectItem value="transactional">
                                Transactional
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="consent-source">Source</Label>
                    <Select
                        value={form.data.source}
                        onValueChange={(value) => form.setData('source', value)}
                    >
                        <SelectTrigger id="consent-source">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(SOURCES).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="consent-evidence">Evidence</Label>
                    <Input
                        id="consent-evidence"
                        value={form.data.evidence}
                        onChange={(event) =>
                            form.setData('evidence', event.target.value)
                        }
                        placeholder="Signup form #1234, checkbox ticked"
                        maxLength={1000}
                    />
                    <InputError message={form.errors.evidence} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="consent-date">Consented at</Label>
                    <Input
                        id="consent-date"
                        type="datetime-local"
                        value={form.data.consented_at}
                        onChange={(event) =>
                            form.setData('consented_at', event.target.value)
                        }
                    />
                    <InputError message={form.errors.consented_at} />
                </div>
                <div className="flex items-end">
                    <Button variant="outline" disabled={form.processing}>
                        Record consent
                    </Button>
                </div>
            </form>
        </section>
    );
}
