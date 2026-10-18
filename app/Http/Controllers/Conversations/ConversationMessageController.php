<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Messages\QueueOutgoingMessage;
use App\Actions\Messages\RetryOutgoingMessage;
use App\Exceptions\MessageActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\SendMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConversationMessageController extends Controller
{
    /**
     * Older messages before a given message id (ULIDs sort chronologically).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $before = $request->validate(['before' => ['required', 'string', 'size:26']])['before'];

        $messages = $conversation->messages()
            ->with(['attachments', 'sender'])
            ->where('id', '<', $before)
            ->orderByDesc('id')
            ->limit(InboxController::MESSAGE_PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'messages' => MessageResource::collection($messages->take(InboxController::MESSAGE_PAGE_SIZE)->reverse()->values())->resolve(),
            'has_older' => $messages->count() > InboxController::MESSAGE_PAGE_SIZE,
        ]);
    }

    /**
     * Queue an outgoing message (text and/or one attachment).
     */
    public function store(SendMessageRequest $request, Conversation $conversation, QueueOutgoingMessage $queue): RedirectResponse
    {
        $queue->handle($conversation, $request->user(), $request->validated('body'), $request->file('attachment'));

        return back();
    }

    /**
     * Explicitly retry a failed or unconfirmed message.
     */
    public function retry(Message $message, RetryOutgoingMessage $retry): RedirectResponse
    {
        try {
            $retry->handle($message);
        } catch (MessageActionException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return back();
    }
}
