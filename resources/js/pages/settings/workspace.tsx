import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { edit } from '@/routes/workspace';

type Settings = {
    workspace_name: string;
    business_hours: {
        timezone: string;
        days: number[];
        start: string;
        end: string;
    };
    auto_reply_enabled: boolean;
    notify_on_new_conversation: boolean;
    notify_on_failed_message: boolean;
};

type Props = {
    settings: Settings;
    providers: {
        value: string;
        label: string;
        enabled: boolean;
        default: boolean;
    }[];
    gateway: {
        url: string;
        token_configured: boolean;
        webhook_secret_configured: boolean;
        previous_secret_configured: boolean;
        webhook_url: string;
    };
    official: {
        api_version: string;
        phone_number_id_configured: boolean;
        token_configured: boolean;
    };
    health?: { name: string; healthy: boolean; summary: string }[];
};

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function Flag({ ok, label }: { ok: boolean; label: string }) {
    return (
        <li className="flex items-center gap-2">
            {ok ? (
                <CheckCircle2
                    className="size-4 text-emerald-600"
                    aria-label="Yes"
                />
            ) : (
                <XCircle
                    className="size-4 text-muted-foreground"
                    aria-label="No"
                />
            )}
            {label}
        </li>
    );
}

export default function WorkspaceSettings({
    settings,
    providers,
    gateway,
    official,
    health,
}: Props) {
    const form = useForm<Settings>(settings);
    const errors = form.errors as Record<string, string | undefined>;
    const setHours = (patch: Partial<Settings['business_hours']>) =>
        form.setData('business_hours', {
            ...form.data.business_hours,
            ...patch,
        });

    return (
        <>
            <Head title="Workspace settings" />
            <h1 className="sr-only">Workspace settings</h1>
            <div className="space-y-10">
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(WorkspaceSettingsController.update.url(), {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Heading
                        variant="small"
                        title="Workspace"
                        description="Shared by everyone in this workspace"
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="workspace-name">Workspace name</Label>
                        <Input
                            id="workspace-name"
                            value={form.data.workspace_name}
                            onChange={(event) =>
                                form.setData(
                                    'workspace_name',
                                    event.target.value,
                                )
                            }
                            maxLength={100}
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Used for the {'{{company_name}}'} placeholder.
                        </p>
                        <InputError message={errors.workspace_name} />
                    </div>

                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            Business hours
                        </legend>
                        <div className="flex flex-wrap gap-3 text-sm">
                            {DAYS.map((label, index) => {
                                const day = index + 1;

                                return (
                                    <label
                                        key={label}
                                        className="flex items-center gap-1.5"
                                    >
                                        <Checkbox
                                            checked={form.data.business_hours.days.includes(
                                                day,
                                            )}
                                            onCheckedChange={(checked) =>
                                                setHours({
                                                    days:
                                                        checked === true
                                                            ? [
                                                                  ...form.data
                                                                      .business_hours
                                                                      .days,
                                                                  day,
                                                              ].sort(
                                                                  (a, b) =>
                                                                      a - b,
                                                              )
                                                            : form.data.business_hours.days.filter(
                                                                  (value) =>
                                                                      value !==
                                                                      day,
                                                              ),
                                                })
                                            }
                                        />
                                        {label}
                                    </label>
                                );
                            })}
                        </div>
                        <InputError message={errors['business_hours.days']} />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="hours-start">Opens</Label>
                                <Input
                                    id="hours-start"
                                    type="time"
                                    value={form.data.business_hours.start}
                                    onChange={(event) =>
                                        setHours({ start: event.target.value })
                                    }
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="hours-end">Closes</Label>
                                <Input
                                    id="hours-end"
                                    type="time"
                                    value={form.data.business_hours.end}
                                    onChange={(event) =>
                                        setHours({ end: event.target.value })
                                    }
                                />
                                <InputError
                                    message={errors['business_hours.end']}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="hours-tz">Timezone</Label>
                                <Input
                                    id="hours-tz"
                                    value={form.data.business_hours.timezone}
                                    onChange={(event) =>
                                        setHours({
                                            timezone: event.target.value,
                                        })
                                    }
                                    placeholder="Europe/Berlin"
                                />
                                <InputError
                                    message={errors['business_hours.timezone']}
                                />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset className="space-y-2 text-sm">
                        <legend className="mb-1 font-medium">
                            Automation &amp; notifications
                        </legend>
                        <label className="flex items-start gap-2">
                            <Checkbox
                                checked={form.data.auto_reply_enabled}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'auto_reply_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            <span>
                                Allow automation rules to send automatic replies
                                <span className="block text-xs text-muted-foreground">
                                    Off by default. Only applies to providers
                                    that support it and to rules with an
                                    auto-reply action.
                                </span>
                            </span>
                        </label>
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={form.data.notify_on_new_conversation}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'notify_on_new_conversation',
                                        checked === true,
                                    )
                                }
                            />
                            Notify everyone when a new conversation starts
                        </label>
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={form.data.notify_on_failed_message}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'notify_on_failed_message',
                                        checked === true,
                                    )
                                }
                            />
                            Notify the sender when a message fails or is
                            unconfirmed
                        </label>
                    </fieldset>

                    <Button disabled={form.processing || !form.isDirty}>
                        Save workspace settings
                    </Button>
                </form>

                <section className="space-y-3 text-sm">
                    <Heading
                        variant="small"
                        title="Messaging providers"
                        description="Chosen per device when it is added. Enabled by server configuration (.env), not here."
                    />
                    <ul className="divide-y rounded-xl border">
                        {providers.map((provider) => (
                            <li
                                key={provider.value}
                                className="flex items-center justify-between gap-2 p-3"
                            >
                                <span>
                                    {provider.label}
                                    {provider.default && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · default for new devices
                                        </span>
                                    )}
                                </span>
                                <span
                                    className={
                                        provider.enabled
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    {provider.enabled ? 'Enabled' : 'Disabled'}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-xs text-muted-foreground">
                        The unofficial WhatsApp Web provider is for controlled
                        testing with consenting test accounts and is not
                        authorized for production messaging.
                    </p>
                </section>

                <section className="space-y-3 text-sm">
                    <Heading
                        variant="small"
                        title="Gateway"
                        description="Internal WhatsApp Web sidecar. Secrets are never shown."
                    />
                    <p className="font-mono text-xs break-all text-muted-foreground">
                        {gateway.url}
                    </p>
                    <ul className="space-y-1">
                        <Flag
                            ok={gateway.token_configured}
                            label="API token configured"
                        />
                        <Flag
                            ok={gateway.webhook_secret_configured}
                            label="Webhook secret configured"
                        />
                        <Flag
                            ok={gateway.previous_secret_configured}
                            label="Previous webhook secret set (rotation in progress)"
                        />
                    </ul>
                    <p className="text-xs text-muted-foreground">
                        Webhook endpoint:{' '}
                        <span className="font-mono break-all">
                            {gateway.webhook_url}
                        </span>
                    </p>
                    <Heading variant="small" title="WhatsApp Cloud API" />
                    <ul className="space-y-1">
                        <Flag
                            ok={official.token_configured}
                            label="Access token configured"
                        />
                        <Flag
                            ok={official.phone_number_id_configured}
                            label={`Phone number id configured (Graph ${official.api_version})`}
                        />
                    </ul>
                </section>

                <section className="space-y-3 text-sm">
                    <Heading variant="small" title="Health" />
                    {health === undefined ? (
                        <div className="space-y-2">
                            <Skeleton className="h-5 w-1/2" />
                            <Skeleton className="h-5 w-2/3" />
                        </div>
                    ) : (
                        <ul className="space-y-1">
                            {health.map((check) => (
                                <Flag
                                    key={check.name}
                                    ok={check.healthy}
                                    label={`${check.name} · ${check.summary}`}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

WorkspaceSettings.layout = {
    breadcrumbs: [{ title: 'Workspace settings', href: edit() }],
};
