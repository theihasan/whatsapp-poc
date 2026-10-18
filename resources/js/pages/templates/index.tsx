import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import TemplateController from '@/actions/App/Http/Controllers/Templates/TemplateController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, postJson } from '@/lib/http';

type Template = {
    id: number;
    name: string;
    category: string | null;
    body: string;
    status: 'draft' | 'active';
};

function TemplateDialog({
    template,
    variables,
    open,
    onOpenChange,
}: {
    template: Template | null;
    variables: Record<string, string>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        name: template?.name ?? '',
        category: template?.category ?? '',
        body: template?.body ?? '',
        status: template?.status ?? 'draft',
    });
    const [rendered, setRendered] = useState('');
    const [previewError, setPreviewError] = useState<string | null>(null);
    const textarea = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        if (form.data.body.trim() === '') {
            setRendered('');

            return;
        }

        const timer = window.setTimeout(() => {
            postJson<{ rendered: string }>(TemplateController.preview.url(), {
                body: form.data.body,
            })
                .then((response) => {
                    setRendered(response.rendered);
                    setPreviewError(null);
                })
                .catch((error: unknown) => {
                    const body =
                        error instanceof HttpError
                            ? (error.body as {
                                  errors?: { body?: string[] };
                              } | null)
                            : null;
                    setPreviewError(
                        body?.errors?.body?.[0] ?? 'Preview unavailable.',
                    );
                });
        }, 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.body]);

    const insertVariable = (name: string) => {
        const element = textarea.current;
        const start = element?.selectionStart ?? form.data.body.length;
        form.setData(
            'body',
            `${form.data.body.slice(0, start)}{{${name}}}${form.data.body.slice(element?.selectionEnd ?? start)}`,
        );
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (template) {
            form.patch(TemplateController.update.url(template.id), options);
        } else {
            form.post(TemplateController.store.url(), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {template ? 'Edit template' : 'New template'}
                    </DialogTitle>
                    <DialogDescription>
                        Reusable text for agents. Not a Meta-approved WhatsApp
                        message template.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="template-name">Name</Label>
                        <Input
                            id="template-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            required
                            maxLength={100}
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="template-category">Category</Label>
                        <Input
                            id="template-category"
                            value={form.data.category}
                            onChange={(event) =>
                                form.setData('category', event.target.value)
                            }
                            maxLength={64}
                            placeholder="Support"
                        />
                    </div>
                    <div className="grid gap-1.5 sm:col-span-2">
                        <Label htmlFor="template-body">Text</Label>
                        <Textarea
                            id="template-body"
                            ref={textarea}
                            value={form.data.body}
                            onChange={(event) =>
                                form.setData('body', event.target.value)
                            }
                            rows={5}
                            maxLength={4096}
                            required
                        />
                        <div className="flex flex-wrap gap-1">
                            {Object.entries(variables).map(([name, label]) => (
                                <button
                                    key={name}
                                    type="button"
                                    onClick={() => insertVariable(name)}
                                    className="rounded-md border px-2 py-0.5 font-mono text-xs hover:bg-accent"
                                    title={label}
                                >
                                    {`{{${name}}}`}
                                </button>
                            ))}
                        </div>
                        <InputError
                            message={
                                form.errors.body ?? previewError ?? undefined
                            }
                        />
                    </div>
                    <div className="grid gap-1.5 sm:col-span-2">
                        <span className="text-sm font-medium">
                            Preview with sample data
                        </span>
                        <p className="min-h-12 rounded-lg bg-muted p-3 text-sm whitespace-pre-wrap">
                            {rendered || '—'}
                        </p>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="template-status">Status</Label>
                        <Select
                            value={form.data.status}
                            onValueChange={(value) =>
                                form.setData(
                                    'status',
                                    value as Template['status'],
                                )
                            }
                        >
                            <SelectTrigger id="template-status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="draft">Draft</SelectItem>
                                <SelectItem value="active">
                                    Active (available in the inbox)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter className="items-end sm:col-span-2">
                        <Button disabled={form.processing}>
                            Save template
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function TemplatesIndex({
    templates,
    categories,
    variables,
    filters,
}: {
    templates: Template[];
    categories: string[];
    variables: Record<string, string>;
    filters: { search: string; category: string | null };
}) {
    const [editing, setEditing] = useState<Template | null>(null);
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState(filters.search);
    const firstRender = useRef(true);

    const visit = (next: typeof filters) =>
        router.get(
            TemplateController.index.url(),
            Object.fromEntries(Object.entries(next).filter(([, v]) => v)),
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
            <Head title="Templates" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Templates"
                        description="Reusable replies with placeholders. These are local texts, not Meta-approved WhatsApp templates."
                    />
                    <Button
                        onClick={() => {
                            setEditing(null);
                            setOpen(true);
                        }}
                    >
                        <Plus />
                        New template
                    </Button>
                </div>

                <div className="flex flex-wrap gap-2">
                    <div className="relative min-w-56 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search templates"
                            aria-label="Search templates"
                            className="pl-8"
                        />
                    </div>
                    {categories.length > 0 && (
                        <Select
                            value={filters.category ?? 'all'}
                            onValueChange={(category) =>
                                visit({
                                    ...filters,
                                    category:
                                        category === 'all' ? null : category,
                                })
                            }
                        >
                            <SelectTrigger
                                className="w-44"
                                aria-label="Filter by category"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All categories
                                </SelectItem>
                                {categories.map((category) => (
                                    <SelectItem key={category} value={category}>
                                        {category}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>

                {templates.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No templates yet.
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {templates.map((template) => (
                            <article
                                key={template.id}
                                className="flex flex-col gap-2 rounded-xl border p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <h2 className="font-medium">
                                            {template.name}
                                        </h2>
                                        {template.category && (
                                            <p className="text-xs text-muted-foreground">
                                                {template.category}
                                            </p>
                                        )}
                                    </div>
                                    <Badge
                                        variant={
                                            template.status === 'active'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {template.status === 'active'
                                            ? 'Active'
                                            : 'Draft'}
                                    </Badge>
                                </div>
                                <p className="line-clamp-4 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {template.body}
                                </p>
                                <div className="mt-auto flex gap-2 pt-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setEditing(template);
                                            setOpen(true);
                                        }}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            window.confirm(
                                                `Delete “${template.name}”?`,
                                            ) &&
                                            router.delete(
                                                TemplateController.destroy.url(
                                                    template.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
            {open && (
                <TemplateDialog
                    key={editing?.id ?? 'new'}
                    template={editing}
                    variables={variables}
                    open={open}
                    onOpenChange={setOpen}
                />
            )}
        </>
    );
}

TemplatesIndex.layout = {
    breadcrumbs: [{ title: 'Templates', href: TemplateController.index() }],
};
