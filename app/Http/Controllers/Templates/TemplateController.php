<?php

namespace App\Http\Controllers\Templates;

use App\Enums\TemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\TemplateRequest;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Rules\KnownTemplatePlaceholders;
use App\Services\AuditLogger;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var array{search?: string|null, category?: string|null} $filters */
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);

        $like = isset($filters['search']) ? '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['search']).'%' : null;

        return Inertia::render('templates/index', [
            'templates' => MessageTemplate::query()
                ->when($like, fn (Builder $query, string $like) => $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('body', 'like', $like)))
                ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'body', 'status'])
                ->map(fn (MessageTemplate $template): array => [...$template->toArray(), 'status' => $template->status->value]),
            'categories' => MessageTemplate::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'variables' => TemplateRenderer::VARIABLES,
            'filters' => ['search' => $filters['search'] ?? '', 'category' => $filters['category'] ?? null],
        ]);
    }

    public function store(TemplateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $template = MessageTemplate::query()->create($request->validated());
        $audit->record('template.created', $template);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back();
    }

    public function update(TemplateRequest $request, MessageTemplate $template, AuditLogger $audit): RedirectResponse
    {
        $template->update($request->validated());
        $audit->record('template.updated', $template);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back();
    }

    public function destroy(MessageTemplate $template, AuditLogger $audit): RedirectResponse
    {
        $audit->record('template.deleted', $template);
        $template->delete();

        return back();
    }

    /**
     * Render an unsaved body with sample data (template editor preview).
     */
    public function preview(Request $request, TemplateRenderer $renderer): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:4096', new KnownTemplatePlaceholders]])['body'];

        return response()->json(['rendered' => $renderer->render($body, TemplateRenderer::SAMPLE)]);
    }

    /**
     * Active templates rendered for a specific conversation (inbox composer).
     */
    public function forConversation(Request $request, Conversation $conversation, TemplateRenderer $renderer): JsonResponse
    {
        $values = $renderer->valuesFor($conversation->contact, $request->user());

        return response()->json([
            'templates' => MessageTemplate::query()
                ->where('status', TemplateStatus::Active)
                ->orderBy('name')
                ->get()
                ->map(fn (MessageTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'category' => $template->category,
                    'rendered' => $renderer->render($template->body, $values),
                ]),
        ]);
    }
}
