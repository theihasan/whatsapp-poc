<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MessagingProviderName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\WorkspaceSettingsRequest;
use App\Services\AuditLogger;
use App\Services\Messaging\MessagingProviderRegistry;
use App\Services\Reporting\DashboardMetrics;
use App\Services\WorkspaceSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceSettingsController extends Controller
{
    /**
     * Workspace settings plus a read-only view of provider/gateway configuration.
     * Only whether secrets are set is shown — never their values.
     */
    public function edit(WorkspaceSettings $settings, MessagingProviderRegistry $providers, DashboardMetrics $metrics): Response
    {
        return Inertia::render('settings/workspace', [
            'settings' => $settings->all(),
            'providers' => array_map(fn (MessagingProviderName $name): array => [
                'value' => $name->value,
                'label' => $name->label(),
                'enabled' => $providers->isEnabled($name),
                'default' => $providers->defaultProvider() === $name,
            ], MessagingProviderName::cases()),
            'gateway' => [
                'url' => config('messaging.gateway.url'),
                'token_configured' => filled(config('messaging.gateway.token')),
                'webhook_secret_configured' => filled(config('messaging.gateway.webhook_secret')),
                'previous_secret_configured' => filled(config('messaging.gateway.webhook_secret_previous')),
                'webhook_url' => route('gateway.events'),
            ],
            'official' => [
                'api_version' => config('messaging.official.api_version'),
                'phone_number_id_configured' => filled(config('messaging.official.phone_number_id')),
                'token_configured' => filled(config('messaging.official.access_token')),
            ],
            'health' => Inertia::defer(fn (): array => $metrics->health()),
        ]);
    }

    public function update(WorkspaceSettingsRequest $request, WorkspaceSettings $settings, AuditLogger $audit): RedirectResponse
    {
        /** @var array<string, mixed> $values */
        $values = $request->validated();
        $values['business_hours']['days'] = array_map(intval(...), $values['business_hours']['days']);
        $settings->update($values);

        $audit->record('settings.updated', null, ['keys' => implode(',', array_keys($values))]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace settings saved.')]);

        return back();
    }
}
