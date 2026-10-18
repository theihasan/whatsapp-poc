<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\WorkspaceSettings;

/**
 * @return array<string, mixed>
 */
function workspacePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'workspace_name' => 'Northwind Support',
        'business_hours' => ['timezone' => 'Europe/Berlin', 'days' => [1, 2, 3, 4, 5, 6], 'start' => '08:00', 'end' => '18:00'],
        'auto_reply_enabled' => true,
        'notify_on_new_conversation' => false,
        'notify_on_failed_message' => true,
    ], $overrides);
}

it('shows only whether secrets are configured, never their values', function () {
    config(['messaging.gateway.token' => 'super-secret-gateway-token', 'messaging.gateway.webhook_secret' => 'super-secret-webhook']);

    $this->actingAs(User::factory()->create())
        ->get(route('workspace.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/workspace')->where('gateway.token_configured', true))
        ->assertDontSee('super-secret-gateway-token')
        ->assertDontSee('super-secret-webhook');
});

it('saves workspace settings and audits the change', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('workspace.update'), workspacePayload())
        ->assertRedirect();

    $settings = app(WorkspaceSettings::class);
    expect($settings->workspaceName())->toBe('Northwind Support')
        ->and($settings->autoReplyEnabled())->toBeTrue()
        ->and($settings->businessHours()['days'])->toBe([1, 2, 3, 4, 5, 6]);
    expect(AuditLog::query()->where('action', 'settings.updated')->exists())->toBeTrue();
});

it('rejects closing time before opening time and invalid timezones', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('workspace.update'), workspacePayload(['business_hours' => ['timezone' => 'Mars/Olympus', 'start' => '18:00', 'end' => '09:00']]))
        ->assertSessionHasErrors(['business_hours.timezone', 'business_hours.end' => 'Closing time must be after opening time.']);
});
