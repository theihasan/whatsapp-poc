<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Single-workspace POC (docs/decisions.md D-002/D-013): every authenticated user may
| listen. Guests are rejected by the broadcasting auth route before these run.
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, int|string $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('devices', fn (User $user): bool => true);

Broadcast::channel('conversations', fn (User $user): bool => true);

Broadcast::channel('conversations.{conversationId}', function (User $user, string $conversationId): bool {
    return Conversation::query()->whereKey($conversationId)->exists();
});
