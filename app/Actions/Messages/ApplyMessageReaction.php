<?php

namespace App\Actions\Messages;

use App\Events\MessageStatusUpdated;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Applies `message.reaction`: each person has at most one reaction per message; a new
 * emoji replaces theirs and an empty emoji removes it. Reactions never create messages
 * or change unread counts.
 */
class ApplyMessageReaction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(WhatsAppAccount $account, array $payload): bool
    {
        $target = Arr::get($payload, 'targetExternalId');
        $reactorPath = Arr::get($payload, 'chatType') === 'group' ? 'participant' : 'from';
        $reactor = Arr::get($payload, "{$reactorPath}.jid");

        if (! is_string($target) || $target === '' || ! is_string($reactor) || $reactor === '') {
            return false;
        }

        $emoji = mb_substr((string) Arr::get($payload, 'emoji', ''), 0, 16);
        $name = Arr::get($payload, "{$reactorPath}.pushName") ?? Arr::get($payload, "{$reactorPath}.phoneNumber");

        return DB::transaction(function () use ($account, $target, $reactor, $emoji, $name, $payload): bool {
            $message = Message::query()
                ->where('whatsapp_account_id', $account->id)
                ->where('external_id', $target)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return false;
            }

            $reactions = array_values(array_filter(
                $message->reactions ?? [],
                fn (array $reaction): bool => $reaction['reactor'] !== $reactor,
            ));

            if ($emoji !== '') {
                $reactions[] = [
                    'reactor' => $reactor,
                    'name' => is_string($name) && $name !== '' ? mb_substr($name, 0, 100) : 'Someone',
                    'emoji' => $emoji,
                    'at' => (string) Arr::get($payload, 'timestamp', now()->toIso8601String()),
                ];
            }

            $message->reactions = $reactions === [] ? null : $reactions;
            $message->save();

            MessageStatusUpdated::dispatch($message);

            return true;
        });
    }
}
