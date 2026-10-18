<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\UpdateConversation;
use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\UpdateConversationRequest;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    /**
     * Open (or create) the conversation between a device and a contact.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'string', Rule::exists(Contact::class, 'id')],
            'whatsapp_account_id' => ['required', 'string', Rule::exists(WhatsAppAccount::class, 'id')],
        ]);

        $conversation = Conversation::query()->firstOrCreate(
            ['whatsapp_account_id' => $validated['whatsapp_account_id'], 'contact_id' => $validated['contact_id']],
            ['status' => ConversationStatus::Open, 'last_message_at' => now()],
        );

        return to_route('inbox.show', $conversation);
    }

    /**
     * Change status or assignee.
     */
    public function update(UpdateConversationRequest $request, Conversation $conversation, UpdateConversation $update): RedirectResponse
    {
        /** @var array{status?: string, assigned_user_id?: int|null} $changes */
        $changes = $request->validated();
        $update->handle($conversation, $changes);

        return back();
    }
}
