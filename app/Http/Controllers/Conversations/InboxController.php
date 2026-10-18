<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\MarkConversationRead;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public const MESSAGE_PAGE_SIZE = 50;

    public const CONVERSATION_LIMIT = 100;

    /**
     * Three-panel inbox. With a conversation, also opens it and marks it read.
     */
    public function __invoke(Request $request, MarkConversationRead $markRead, ?Conversation $conversation = null): Response
    {
        /** @var array{account?: string|null, filter?: string|null, search?: string|null} $filters */
        $filters = $request->validate([
            'account' => ['nullable', 'string', 'max:26'],
            'filter' => ['nullable', 'in:all,unread,open,closed,mine'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $conversations = Conversation::query()
            ->with(['contact', 'assignee'])
            ->when($filters['account'] ?? null, fn (Builder $query, string $account) => $query->where('whatsapp_account_id', $account))
            ->filter($filters['filter'] ?? null, $user)
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->whereHas('contact', fn (Builder $contact) => $contact->search($search)))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::CONVERSATION_LIMIT)
            ->get();

        if ($conversation !== null) {
            $markRead->handle($conversation);
        }

        return Inertia::render('inbox/index', [
            'filters' => [
                'account' => $filters['account'] ?? null,
                'filter' => $filters['filter'] ?? 'all',
                'search' => $filters['search'] ?? '',
            ],
            'accounts' => WhatsAppAccount::query()->orderBy('name')->get(['id', 'name', 'status', 'provider'])->map(fn (WhatsAppAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'status' => $account->status->value,
                'provider' => $account->provider->value,
            ]),
            'conversations' => ConversationResource::collection($conversations)->resolve(),
            'agents' => User::query()->orderBy('name')->get(['id', 'name']),
            'active' => $conversation === null ? null : $this->active($conversation),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function active(Conversation $conversation): array
    {
        $conversation->load(['contact.tags', 'contact.consents', 'assignee', 'whatsappAccount']);

        $messages = $conversation->messages()
            ->with(['attachments', 'sender'])
            ->orderByDesc('id')
            ->limit(self::MESSAGE_PAGE_SIZE + 1)
            ->get();

        /** @var Contact $contact */
        $contact = $conversation->contact;

        return [
            'conversation' => (new ConversationResource($conversation))->resolve(),
            'contact' => (new ContactResource($contact))->resolve(),
            'device' => [
                'id' => $conversation->whatsappAccount->id,
                'name' => $conversation->whatsappAccount->name,
                'status' => $conversation->whatsappAccount->status->value,
                'provider' => $conversation->whatsappAccount->provider->value,
            ],
            'messages' => MessageResource::collection($messages->take(self::MESSAGE_PAGE_SIZE)->reverse()->values())->resolve(),
            'has_older' => $messages->count() > self::MESSAGE_PAGE_SIZE,
        ];
    }
}
