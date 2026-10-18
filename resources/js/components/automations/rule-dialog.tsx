import { useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import AutomationRuleController from '@/actions/App/Http/Controllers/Automations/AutomationRuleController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type {
    AutomationAction,
    AutomationActionType,
    AutomationRule,
} from '@/types';

const ACTION_LABELS: Record<AutomationActionType, string> = {
    assign: 'Assign conversation',
    add_tag: 'Add tag to contact',
    notify: 'Notify team (internal)',
    suggest_reply: 'Suggest a reply (agent sends)',
    auto_reply: 'Send automatic reply',
};

type FormData = {
    name: string;
    is_enabled: boolean;
    is_dry_run: boolean;
    priority: number;
    max_runs_per_conversation_per_hour: number;
    keywords: string;
    business_hours: 'any' | 'inside' | 'outside';
    tag_ids: number[];
    actions: AutomationAction[];
};

export default function RuleDialog({
    rule,
    users,
    tags,
    autoReplyEnabled,
    open,
    onOpenChange,
}: {
    rule: AutomationRule | null;
    users: { id: number; name: string }[];
    tags: { id: number; name: string }[];
    autoReplyEnabled: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<FormData>({
        name: rule?.name ?? '',
        is_enabled: rule?.is_enabled ?? true,
        is_dry_run: rule?.is_dry_run ?? true,
        priority: rule?.priority ?? 100,
        max_runs_per_conversation_per_hour:
            rule?.max_runs_per_conversation_per_hour ?? 1,
        keywords: rule?.conditions.keywords?.join(', ') ?? '',
        business_hours: rule?.conditions.business_hours ?? 'any',
        tag_ids: rule?.conditions.tag_ids ?? [],
        actions: rule?.actions ?? [{ type: 'suggest_reply', text: '' }],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setAction = (index: number, patch: Partial<AutomationAction>) =>
        form.setData(
            'actions',
            form.data.actions.map((action, i) =>
                i === index ? { ...action, ...patch } : action,
            ),
        );

    const submit = () => {
        form.transform((data) => ({
            name: data.name,
            is_enabled: data.is_enabled,
            is_dry_run: data.is_dry_run,
            priority: data.priority,
            max_runs_per_conversation_per_hour:
                data.max_runs_per_conversation_per_hour,
            conditions: {
                keywords: data.keywords
                    .split(',')
                    .map((keyword) => keyword.trim())
                    .filter(Boolean),
                business_hours:
                    data.business_hours === 'any' ? null : data.business_hours,
                tag_ids: data.tag_ids,
            },
            actions: data.actions,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (rule) {
            form.patch(AutomationRuleController.update.url(rule.id), options);
        } else {
            form.post(AutomationRuleController.store.url(), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{rule ? 'Edit rule' : 'New rule'}</DialogTitle>
                    <DialogDescription>
                        Runs on every incoming message that matches all
                        conditions. New rules start in dry-run mode.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-1.5 sm:col-span-3">
                            <Label htmlFor="rule-name">Name</Label>
                            <Input
                                id="rule-name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                maxLength={100}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="rule-priority">
                                Priority (lower runs first)
                            </Label>
                            <Input
                                id="rule-priority"
                                type="number"
                                min={1}
                                max={1000}
                                value={form.data.priority}
                                onChange={(event) =>
                                    form.setData(
                                        'priority',
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="rule-limit">
                                Max runs per conversation / hour
                            </Label>
                            <Input
                                id="rule-limit"
                                type="number"
                                min={1}
                                max={60}
                                value={
                                    form.data.max_runs_per_conversation_per_hour
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'max_runs_per_conversation_per_hour',
                                        Number(event.target.value),
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-col justify-end gap-2 text-sm">
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.is_enabled}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                Enabled
                            </label>
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={form.data.is_dry_run}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'is_dry_run',
                                            checked === true,
                                        )
                                    }
                                />
                                Dry run (log only)
                            </label>
                        </div>
                    </div>

                    <fieldset className="space-y-3 rounded-xl border p-4">
                        <legend className="px-1 text-sm font-medium">
                            Conditions
                        </legend>
                        <div className="grid gap-1.5">
                            <Label htmlFor="rule-keywords">
                                Message contains any of these words
                            </Label>
                            <Input
                                id="rule-keywords"
                                value={form.data.keywords}
                                onChange={(event) =>
                                    form.setData('keywords', event.target.value)
                                }
                                placeholder="price, pricing, cost"
                            />
                            <InputError
                                message={
                                    errors['conditions.keywords.0'] ??
                                    errors.conditions
                                }
                            />
                        </div>
                        <div className="grid gap-1.5 sm:w-1/2">
                            <Label htmlFor="rule-hours">Business hours</Label>
                            <Select
                                value={form.data.business_hours}
                                onValueChange={(value) =>
                                    form.setData(
                                        'business_hours',
                                        value as FormData['business_hours'],
                                    )
                                }
                            >
                                <SelectTrigger id="rule-hours">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="any">
                                        Any time
                                    </SelectItem>
                                    <SelectItem value="inside">
                                        During business hours
                                    </SelectItem>
                                    <SelectItem value="outside">
                                        Outside business hours
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {tags.length > 0 && (
                            <div className="space-y-1.5">
                                <span className="text-sm font-medium">
                                    Contact has any of these tags
                                </span>
                                <div className="flex flex-wrap gap-3 text-sm">
                                    {tags.map((tag) => (
                                        <label
                                            key={tag.id}
                                            className="flex items-center gap-1.5"
                                        >
                                            <Checkbox
                                                checked={form.data.tag_ids.includes(
                                                    tag.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'tag_ids',
                                                        checked === true
                                                            ? [
                                                                  ...form.data
                                                                      .tag_ids,
                                                                  tag.id,
                                                              ]
                                                            : form.data.tag_ids.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      tag.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {tag.name}
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}
                    </fieldset>

                    <fieldset className="space-y-3 rounded-xl border p-4">
                        <legend className="px-1 text-sm font-medium">
                            Actions
                        </legend>
                        {form.data.actions.map((action, index) => (
                            <div
                                key={index}
                                className="space-y-2 rounded-lg bg-muted/40 p-3"
                            >
                                <div className="flex gap-2">
                                    <Select
                                        value={action.type}
                                        onValueChange={(type) =>
                                            setAction(index, {
                                                type: type as AutomationActionType,
                                            })
                                        }
                                    >
                                        <SelectTrigger aria-label="Action type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(ACTION_LABELS).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove action"
                                        disabled={
                                            form.data.actions.length === 1
                                        }
                                        onClick={() =>
                                            form.setData(
                                                'actions',
                                                form.data.actions.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                                {(action.type === 'assign' ||
                                    action.type === 'notify') && (
                                    <Select
                                        value={
                                            action.user_id
                                                ? String(action.user_id)
                                                : 'all'
                                        }
                                        onValueChange={(value) =>
                                            setAction(index, {
                                                user_id:
                                                    value === 'all'
                                                        ? null
                                                        : Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger aria-label="User">
                                            <SelectValue placeholder="Choose a user" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {action.type === 'notify' && (
                                                <SelectItem value="all">
                                                    Everyone
                                                </SelectItem>
                                            )}
                                            {users.map((user) => (
                                                <SelectItem
                                                    key={user.id}
                                                    value={String(user.id)}
                                                >
                                                    {user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {action.type === 'add_tag' && (
                                    <Select
                                        value={
                                            action.tag_id
                                                ? String(action.tag_id)
                                                : ''
                                        }
                                        onValueChange={(value) =>
                                            setAction(index, {
                                                tag_id: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger aria-label="Tag">
                                            <SelectValue placeholder="Choose a tag" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {tags.map((tag) => (
                                                <SelectItem
                                                    key={tag.id}
                                                    value={String(tag.id)}
                                                >
                                                    {tag.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {(action.type === 'suggest_reply' ||
                                    action.type === 'auto_reply') && (
                                    <Textarea
                                        value={action.text ?? ''}
                                        onChange={(event) =>
                                            setAction(index, {
                                                text: event.target.value,
                                            })
                                        }
                                        placeholder="Hi {{contact_name}}, …"
                                        rows={3}
                                        maxLength={4096}
                                        aria-label="Reply text"
                                    />
                                )}
                                {action.type === 'auto_reply' &&
                                    !autoReplyEnabled && (
                                        <p className="text-xs text-amber-700 dark:text-amber-400">
                                            Automatic replies are disabled in
                                            workspace settings; this action will
                                            be skipped.
                                        </p>
                                    )}
                                <InputError
                                    message={
                                        errors[`actions.${index}.user_id`] ??
                                        errors[`actions.${index}.tag_id`] ??
                                        errors[`actions.${index}.text`]
                                    }
                                />
                            </div>
                        ))}
                        {form.data.actions.length < 5 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('actions', [
                                        ...form.data.actions,
                                        { type: 'notify', user_id: null },
                                    ])
                                }
                            >
                                Add action
                            </Button>
                        )}
                        <InputError message={errors.actions} />
                    </fieldset>

                    <DialogFooter>
                        <Button disabled={form.processing}>Save rule</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
