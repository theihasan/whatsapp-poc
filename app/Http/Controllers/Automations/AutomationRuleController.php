<?php

namespace App\Http\Controllers\Automations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automations\AutomationRuleRequest;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Templates\TemplateRenderer;
use App\Services\WorkspaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomationRuleController extends Controller
{
    public function index(WorkspaceSettings $settings): Response
    {
        return Inertia::render('automations/index', [
            'rules' => AutomationRule::query()
                ->withCount(['executions as runs_last_day' => fn ($query) => $query->where('created_at', '>=', now()->subDay())])
                ->orderBy('priority')
                ->orderBy('id')
                ->get(),
            'executions' => AutomationExecution::query()
                ->with(['rule:id,name', 'conversation.contact:id,name,phone_number,wa_jid'])
                ->latest('created_at')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (AutomationExecution $execution): array => [
                    'id' => $execution->id,
                    'rule' => $execution->rule->name,
                    'status' => $execution->status->value,
                    'actions_taken' => $execution->actions_taken ?? [],
                    'error' => $execution->error,
                    'contact' => $execution->conversation?->contact->displayName(),
                    'conversation_id' => $execution->conversation_id,
                    'created_at' => $execution->created_at->toIso8601String(),
                ]),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'variables' => TemplateRenderer::VARIABLES,
            'autoReplyEnabled' => $settings->autoReplyEnabled(),
        ]);
    }

    public function store(AutomationRuleRequest $request, AuditLogger $audit): RedirectResponse
    {
        $rule = AutomationRule::query()->create([...$request->rule(), 'trigger' => 'incoming_message']);
        $audit->record('automation.created', $rule);

        return back();
    }

    public function update(AutomationRuleRequest $request, AutomationRule $automation, AuditLogger $audit): RedirectResponse
    {
        $automation->update($request->rule());
        $audit->record('automation.updated', $automation, ['enabled' => $automation->is_enabled, 'dry_run' => $automation->is_dry_run]);

        return back();
    }

    /**
     * Quick enable/disable from the list.
     */
    public function toggle(Request $request, AutomationRule $automation, AuditLogger $audit): RedirectResponse
    {
        $automation->update(['is_enabled' => $request->boolean('is_enabled')]);
        $audit->record($automation->is_enabled ? 'automation.enabled' : 'automation.disabled', $automation);

        return back();
    }

    public function destroy(AutomationRule $automation, AuditLogger $audit): RedirectResponse
    {
        $audit->record('automation.deleted', $automation);
        $automation->delete();

        return back();
    }
}
