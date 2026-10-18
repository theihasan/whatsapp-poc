<?php

namespace App\Jobs;

use App\DTOs\OutgoingAttachment;
use App\DTOs\OutgoingMessage;
use App\DTOs\SendResult;
use App\Enums\MessageStatus;
use App\Enums\SendOutcome;
use App\Events\MessageStatusUpdated;
use App\Exceptions\ProviderNotEnabledException;
use App\Exceptions\ProviderUnavailableException;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Notifications\MessageDeliveryFailed;
use App\Services\Messaging\MessagingProviderRegistry;
use App\Services\WorkspaceSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one outgoing message through its account's provider.
 *
 * - provider unreachable / session not connected → retried with backoff (not sent yet)
 * - rejected → failed
 * - timeout after dispatch → unknown (never resent automatically)
 * - a crashed earlier attempt left it "processing" → resent only when the provider
 *   deduplicates by idempotency key, otherwise marked unknown
 */
class SendOutgoingMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public Message $message)
    {
        $this->onQueue('messages');
    }

    public function uniqueId(): string
    {
        return $this->message->id;
    }

    public function handle(MessagingProviderRegistry $providers): void
    {
        $claimed = $this->claim();

        if ($claimed === null) {
            return;
        }

        [$message, $interruptedBefore] = $claimed;

        try {
            $provider = $providers->for($message->whatsappAccount);
        } catch (ProviderNotEnabledException $exception) {
            $this->finish($message, SendResult::rejected($exception->getMessage()));

            return;
        }

        if ($interruptedBefore && ! $provider->capabilities()->idempotentSends) {
            $this->finish($message, SendResult::unknown('A previous attempt was interrupted; not resent to avoid a duplicate.'));

            return;
        }

        if ($message->contact->wa_jid === null) {
            $this->finish($message, SendResult::rejected('This contact has no WhatsApp identifier.'));

            return;
        }

        try {
            $result = $provider->sendMessage($message->whatsappAccount, $this->outgoing($message));
        } catch (ProviderUnavailableException $exception) {
            $message->update(['status' => MessageStatus::Queued, 'failure_reason' => 'Waiting to retry: '.$exception->getMessage()]);
            MessageStatusUpdated::dispatch($message);
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);

            return;
        }

        $this->finish($message, $result);
    }

    public function failed(?Throwable $exception): void
    {
        $message = $this->message->fresh();

        if ($message !== null && in_array($message->status, [MessageStatus::Queued, MessageStatus::Processing], true)) {
            $message->update([
                'status' => MessageStatus::Failed,
                'failure_reason' => Str::limit('Delivery gave up: '.($exception?->getMessage() ?? 'provider unavailable'), 1000),
            ]);
            MessageStatusUpdated::dispatch($message);
            self::notifyFailure($message);
        }
    }

    /**
     * Tell the sender (or everyone, for automated messages) that a message did not go out.
     */
    private static function notifyFailure(Message $message): void
    {
        if (! app(WorkspaceSettings::class)->get('notify_on_failed_message')) {
            return;
        }

        $recipients = $message->user_id === null ? User::query()->get() : User::query()->whereKey($message->user_id)->get();
        Notification::send($recipients, new MessageDeliveryFailed($message));
    }

    /**
     * Moves the message to `processing`. Returns null when there is nothing to do, else the
     * message and whether an earlier attempt was interrupted mid-send.
     *
     * @return array{0: Message, 1: bool}|null
     */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $message = Message::query()->with(['whatsappAccount', 'contact', 'attachments'])->lockForUpdate()->find($this->message->id);

            if ($message === null || ! in_array($message->status, [MessageStatus::Queued, MessageStatus::Processing], true)) {
                return null;
            }

            $interruptedBefore = $message->status === MessageStatus::Processing;
            $message->status = MessageStatus::Processing;
            $message->save();
            MessageStatusUpdated::dispatch($message);

            return [$message, $interruptedBefore];
        });
    }

    private function finish(Message $message, SendResult $result): void
    {
        DB::transaction(function () use ($message, $result): void {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);

            if ($result->externalId !== null && $locked->external_id === null) {
                $locked->external_id = $result->externalId;
            }

            if (in_array($locked->status, [MessageStatus::Queued, MessageStatus::Processing], true)) {
                $locked->status = match ($result->outcome) {
                    SendOutcome::Accepted => MessageStatus::GatewayAccepted,
                    SendOutcome::Rejected => MessageStatus::Failed,
                    SendOutcome::Unknown => MessageStatus::Unknown,
                };
                $locked->failure_reason = $result->error === null ? null : Str::limit($result->error, 1000);
            }

            $locked->save();
            MessageStatusUpdated::dispatch($locked);

            if (in_array($locked->status, [MessageStatus::Failed, MessageStatus::Unknown], true)) {
                DB::afterCommit(fn () => self::notifyFailure($locked));
            }
        });
    }

    private function outgoing(Message $message): OutgoingMessage
    {
        /** @var MessageAttachment|null $attachment */
        $attachment = $message->attachments->first();

        return new OutgoingMessage(
            idempotencyKey: (string) $message->idempotency_key,
            recipientJid: (string) $message->contact->wa_jid,
            type: $message->type,
            body: $message->body,
            attachment: $attachment === null ? null : new OutgoingAttachment(
                fileName: $attachment->original_name ?? 'attachment',
                mimeType: $attachment->mime_type,
                base64Contents: base64_encode((string) Storage::disk($attachment->disk)->get($attachment->path)),
            ),
        );
    }
}
