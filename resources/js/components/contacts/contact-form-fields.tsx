import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type ContactFormData = {
    name: string;
    phone_number: string;
    notes: string;
    tags: string[];
};

export function parseTags(value: string): string[] {
    return value
        .split(',')
        .map((tag) => tag.trim().toLowerCase())
        .filter((tag, index, all) => tag !== '' && all.indexOf(tag) === index);
}

export default function ContactFormFields({
    data,
    errors,
    setData,
    phoneEditable = true,
}: {
    data: ContactFormData;
    errors: Partial<Record<string, string>>;
    setData: <K extends keyof ContactFormData>(
        key: K,
        value: ContactFormData[K],
    ) => void;
    phoneEditable?: boolean;
}) {
    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="contact-name">Name</Label>
                <Input
                    id="contact-name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    required
                    maxLength={255}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="contact-phone">WhatsApp number</Label>
                <Input
                    id="contact-phone"
                    value={data.phone_number}
                    onChange={(event) =>
                        setData('phone_number', event.target.value)
                    }
                    placeholder="+1 202 555 0123"
                    inputMode="tel"
                    disabled={!phoneEditable}
                    required={phoneEditable}
                />
                <InputError message={errors.phone_number} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="contact-tags">Tags</Label>
                <Input
                    id="contact-tags"
                    defaultValue={data.tags.join(', ')}
                    onChange={(event) =>
                        setData('tags', parseTags(event.target.value))
                    }
                    placeholder="vip, billing"
                />
                <p className="text-xs text-muted-foreground">
                    Separate tags with commas.
                </p>
                <InputError message={errors.tags ?? errors['tags.0']} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="contact-notes">Notes</Label>
                <Textarea
                    id="contact-notes"
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                    maxLength={5000}
                    rows={4}
                />
                <InputError message={errors.notes} />
            </div>
        </div>
    );
}
