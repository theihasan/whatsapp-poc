import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import AutomationRuleController from '@/actions/App/Http/Controllers/Automations/AutomationRuleController';
import RuleDialog from '@/components/automations/rule-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { formatListTimestamp } from '@/lib/format';
import { show as showConversation } from '@/routes/inbox';
import type { AutomationExecutionRow, AutomationRule } from '@/types';

const STATUS_LABEL: Record<AutomationExecutionRow['status'], string> = {
    executed: 'Executed',
    dry_run: 'Dry run',
    rate_limited: 'Rate limited',
    failed: 'Failed',
};

function describeConditions(
    rule: AutomationRule,
    tags: { id: number; name: string }[],
): string {
    const parts: string[] = [];

    if (rule.conditions.keywords?.length) {
        parts.push(`contains “${rule.conditions.keywords.join('”, “')}”`);
    }

    if (rule.conditions.business_hours) {
        parts.push(
            rule.conditions.business_hours === 'inside'
                ? 'during business hours'
                : 'outside business hours',
        );
    }

    if (rule.conditions.tag_ids?.length) {
        parts.push(
            `tagged ${tags
                .filter((tag) => rule.conditions.tag_ids?.includes(tag.id))
                .map((tag) => tag.name)
                .join(', ')}`,
        );
    }

    return parts.join(' · ');
}

export default function AutomationsIndex({
    rules,
    executions,
    users,
    tags,
    autoReplyEnabled,
}: {
    rules: AutomationRule[];
    executions: AutomationExecutionRow[];
    users: { id: number; name: string }[];
    tags: { id: number; name: string }[];
    variables: Record<string, string>;
    autoReplyEnabled: boolean;
}) {
    const [editing, setEditing] = useState<AutomationRule | null>(null);
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Automations" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Automations"
                        description="Deterministic rules for incoming messages. Rules never run on outgoing or automated messages."
                    />
                    <Button
                        onClick={() => {
                            setEditing(null);
                            setOpen(true);
                        }}
                    >
                        <Plus />
                        New rule
                    </Button>
                </div>

                {rules.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                        No rules yet.
                    </div>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="flex flex-wrap items-center gap-4 p-4"
                            >
                                <Checkbox
                                    checked={rule.is_enabled}
                                    aria-label={`Enable ${rule.name}`}
                                    onCheckedChange={(checked) =>
                                        router.patch(
                                            AutomationRuleController.toggle.url(
                                                rule.id,
                                            ),
                                            { is_enabled: checked === true },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {rule.name}
                                        </span>
                                        {rule.is_dry_run && (
                                            <Badge variant="outline">
                                                Dry run
                                            </Badge>
                                        )}
                                        {!rule.is_enabled && (
                                            <Badge variant="secondary">
                                                Disabled
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {describeConditions(rule, tags)} →{' '}
                                        {rule.actions
                                            .map((action) =>
                                                action.type.replace('_', ' '),
                                            )
                                            .join(', ')}
                                    </p>
                                </div>
                                <span className="text-xs text-muted-foreground">
                                    #{rule.priority} · {rule.runs_last_day} runs
                                    today
                                </span>
                                <div className="flex gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setEditing(rule);
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
                                                `Delete “${rule.name}”?`,
                                            ) &&
                                            router.delete(
                                                AutomationRuleController.destroy.url(
                                                    rule.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <section className="space-y-3">
                    <h2 className="text-base font-medium">Execution log</h2>
                    {executions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No rule has run yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            When
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Rule
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Contact
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Result
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {executions.map((execution) => (
                                        <tr key={execution.id}>
                                            <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                                {formatListTimestamp(
                                                    execution.created_at,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                {execution.rule}
                                            </td>
                                            <td className="px-4 py-2">
                                                {execution.conversation_id ? (
                                                    <Link
                                                        href={showConversation.url(
                                                            execution.conversation_id,
                                                        )}
                                                        className="hover:underline"
                                                    >
                                                        {execution.contact ??
                                                            '—'}
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span
                                                    className={
                                                        execution.status ===
                                                        'failed'
                                                            ? 'text-red-600'
                                                            : ''
                                                    }
                                                >
                                                    {
                                                        STATUS_LABEL[
                                                            execution.status
                                                        ]
                                                    }
                                                </span>
                                                {execution.actions_taken
                                                    .length > 0 && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        ·{' '}
                                                        {execution.actions_taken.join(
                                                            ', ',
                                                        )}
                                                    </span>
                                                )}
                                                {execution.error && (
                                                    <span className="text-red-600">
                                                        {' '}
                                                        · {execution.error}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
            {open && (
                <RuleDialog
                    key={editing?.id ?? 'new'}
                    rule={editing}
                    users={users}
                    tags={tags}
                    autoReplyEnabled={autoReplyEnabled}
                    open={open}
                    onOpenChange={setOpen}
                />
            )}
        </>
    );
}

AutomationsIndex.layout = {
    breadcrumbs: [
        { title: 'Automations', href: AutomationRuleController.index() },
    ],
};
