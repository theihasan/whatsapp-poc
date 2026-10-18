<?php

namespace App\Http\Controllers\Contacts;

use App\Actions\Contacts\SaveContact;
use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\WhatsAppAccount;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    /**
     * Searchable, filterable contact list.
     */
    public function index(Request $request): Response
    {
        /** @var array{search?: string|null, tag?: string|null, consent?: string|null} $filters */
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:64'],
            'consent' => ['nullable', 'in:opted_in,none'],
        ]);

        $contacts = Contact::query()
            ->with('tags')
            ->withExists(['consents as has_consent' => fn (Builder $query) => $query->whereNull('revoked_at')])
            ->search($filters['search'] ?? null)
            ->when($filters['tag'] ?? null, fn (Builder $query, string $tag) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('name', $tag)))
            ->when(($filters['consent'] ?? null) === 'opted_in', fn (Builder $query) => $query->whereHas('consents', fn (Builder $consents) => $consents->whereNull('revoked_at')))
            ->when(($filters['consent'] ?? null) === 'none', fn (Builder $query) => $query->whereDoesntHave('consents', fn (Builder $consents) => $consents->whereNull('revoked_at')))
            ->orderByDesc('last_activity_at')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('contacts/index', [
            'contacts' => $contacts->through(fn (Contact $contact): array => [
                ...(new ContactResource($contact))->resolve(),
                'has_consent' => (bool) $contact->getAttribute('has_consent'),
            ]),
            'tags' => Tag::query()->orderBy('name')->pluck('name'),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'tag' => $filters['tag'] ?? null,
                'consent' => $filters['consent'] ?? null,
            ],
        ]);
    }

    public function store(ContactRequest $request, SaveContact $save): RedirectResponse
    {
        /** @var array{name: string, phone_number: string, notes?: string|null, tags?: list<string>} $data */
        $data = $request->validated();
        $contact = $save->handle(null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact created. No consent is recorded yet.')]);

        return to_route('contacts.show', $contact);
    }

    /**
     * Contact details, consent history, conversations and recent activity.
     */
    public function show(Contact $contact): Response
    {
        $contact->load(['tags', 'consents' => fn ($query) => $query->latest('consented_at')->latest('id')]);

        return Inertia::render('contacts/show', [
            'contact' => (new ContactResource($contact))->resolve(),
            'conversations' => $contact->conversations()
                ->with('whatsappAccount:id,name')
                ->orderByDesc('last_message_at')
                ->get()
                ->map(fn (Conversation $conversation): array => [
                    'id' => $conversation->id,
                    'device' => $conversation->whatsappAccount->name,
                    'status' => $conversation->status->value,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                    'last_message_preview' => $conversation->last_message_preview,
                ]),
            'activity' => [
                'messages_in' => Message::query()->where('contact_id', $contact->id)->where('direction', 'incoming')->count(),
                'messages_out' => Message::query()->where('contact_id', $contact->id)->where('direction', 'outgoing')->count(),
                'events' => AuditLog::query()
                    ->with('user:id,name')
                    ->where('subject_type', 'Contact')
                    ->where('subject_id', $contact->id)
                    ->latest('created_at')
                    ->latest('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (AuditLog $log): array => [
                        'id' => $log->id,
                        'action' => $log->action,
                        'user' => $log->user?->name,
                        'created_at' => $log->created_at->toIso8601String(),
                    ]),
            ],
            'devices' => WhatsAppAccount::query()
                ->where('status', DeviceStatus::Connected)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(ContactRequest $request, Contact $contact, SaveContact $save): RedirectResponse
    {
        /** @var array{name: string, phone_number?: string, notes?: string|null, tags?: list<string>} $data */
        $data = $request->validated();
        $save->handle($contact, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact saved.')]);

        return back();
    }

    public function destroy(Contact $contact, AuditLogger $audit): RedirectResponse
    {
        $audit->record('contact.deleted', $contact);
        $contact->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Contact deleted.')]);

        return to_route('contacts.index');
    }
}
