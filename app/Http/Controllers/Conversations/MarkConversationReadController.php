<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\Conversations\MarkConversationRead;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class MarkConversationReadController extends Controller
{
    /**
     * Reset the unread counter while a conversation is open and new messages arrive.
     */
    public function __invoke(Conversation $conversation, MarkConversationRead $markRead): JsonResponse
    {
        $markRead->handle($conversation);

        return response()->json(['unread_count' => 0]);
    }
}
