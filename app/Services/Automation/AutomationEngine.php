<?php

namespace App\Services\Automation;

use App\Actions\Conversations\UpdateConversation;
use App\Actions\Messages\QueueOutgoingMessage;
use App\Enums\AutomationExecutionStatus;
use App\Enums\DeviceStatus;
use App\Enums\MessageDirection;
use App\Events\ConversationUpdated;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\AutomationTriggered;
use App\Services\Messaging\MessagingProviderRegistry;
use App\Services\Templates\TemplateRenderer;
use App\Services\WorkspaceSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deterministic rules engine for incoming messages.
 *
 * Group chats never trigger rules. Loop prevention: rules only ever see incoming,
 * non-automated messages; auto-replies
 * are marked automated, rate-limited per rule and conversation, and suppressed while a
 * recent automated reply exists. New action types are added in `perform()` — e.g. an
 * AI-backed `suggest_reply` would be one more case there.
 */
class AutomationEngine
{
    public const AUTO_REPLY_COOLDOWN_SECONDS = 60;

    public function __construct(
        private WorkspaceSettings $settings,
        private TemplateRenderer $renderer,
        private UpdateConversation $updateConversation,
        private QueueOutgoingMessage $queueOutgoingMessage,
        private MessagingProviderRegistry $providers,
    ) {}

    public function run(Message $message): void
    {
        if ($message->direction !== MessageDirection::Incoming || $message->is_automated) {
            return;
        }

        $message->loadMissing(['conversation.whatsappAccount', 'contact.tags']);

        if ($message->contact->is_group) {
            return;
        }

        $rules = AutomationRule::query()
            ->where('is_enabled', true)
            ->where('trigger', 'incoming_message')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($rule, $message)) {
                $this->execute($rule, $message);
            }
        }
    }

    public function matches(AutomationRule $rule, Message $message): bool
    {
        $conditions = $rule->conditions;
        $keywords = $conditions['keywords'] ?? [];

        if ($keywords !== []) {
            $text = mb_strtolower((string) $message->body);
            $found = collect($keywords)->contains(
                fn (string $keyword): bool => preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($keyword), '/').'(?![\p{L}\p{N}])/u', $text) === 1,
            );

            if (! $found) {
                return false;
            }
        }

        $hours = $conditions['business_hours'] ?? null;

        if ($hours !== null) {
            $inside = $this->settings->isWithinBusinessHours($message->created_at ?? now());

            if (($hours === 'inside') !== $inside) {
                return false;
            }
        }

        $tagIds = $conditions['tag_ids'] ?? [];

        return $tagIds === [] || $message->contact->tags->pluck('id')->intersect($tagIds)->isNotEmpty();
    }

    private function execute(AutomationRule $rule, Message $message): void
    {
        $recentRuns = $rule->executions()
            ->where('conversation_id', $message->conversation_id)
            ->whereIn('status', [AutomationExecutionStatus::Executed, AutomationExecutionStatus::DryRun])
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentRuns >= $rule->max_runs_per_conversation_per_hour) {
            $this->log($rule, $message, AutomationExecutionStatus::RateLimited, []);

            return;
        }

        if ($rule->is_dry_run) {
            $this->log($rule, $message, AutomationExecutionStatus::DryRun, array_map(
                fn (array $action): string => 'would '.$action['type'],
                $rule->actions,
            ));

            return;
        }

        $taken = [];

        try {
            foreach ($rule->actions as $action) {
                $taken[] = $this->perform($action, $rule, $message);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->log($rule, $message, AutomationExecutionStatus::Failed, $taken, Str::limit($exception->getMessage(), 1000));

            return;
        }

        $this->log($rule, $message, AutomationExecutionStatus::Executed, $taken);
    }

    /**
     * @param  array{type: string, user_id?: int|null, tag_id?: int|null, text?: string|null}  $action
     */
    private function perform(array $action, AutomationRule $rule, Message $message): string
    {
        $conversation = $message->conversation;

        return match ($action['type']) {
            'assign' => $this->assign($conversation, $action['user_id'] ?? null),
            'add_tag' => $this->addTag($message, $action['tag_id'] ?? null),
            'notify' => $this->notify($rule, $message, $action['user_id'] ?? null),
            'suggest_reply' => $this->suggestReply($message, (string) ($action['text'] ?? '')),
            'auto_reply' => $this->autoReply($message, (string) ($action['text'] ?? '')),
            default => 'skipped unknown action '.$action['type'],
        };
    }

    private function assign(Conversation $conversation, ?int $userId): string
    {
        if ($userId === null || ! User::query()->whereKey($userId)->exists()) {
            return 'skipped assign (user missing)';
        }

        $this->updateConversation->handle($conversation, ['assigned_user_id' => $userId]);

        return 'assigned';
    }

    private function addTag(Message $message, ?int $tagId): string
    {
        if ($tagId === null) {
            return 'skipped add_tag (tag missing)';
        }

        $message->contact->tags()->syncWithoutDetaching([$tagId]);

        return 'tagged';
    }

    private function notify(AutomationRule $rule, Message $message, ?int $userId): string
    {
        $recipients = $userId === null ? User::query()->get() : User::query()->whereKey($userId)->get();
        Notification::send($recipients, new AutomationTriggered($rule, $message->conversation));

        return 'notified '.$recipients->count();
    }

    private function suggestReply(Message $message, string $text): string
    {
        $conversation = $message->conversation;
        $conversation->update(['suggested_reply' => $this->renderFor($message, $text)]);
        ConversationUpdated::dispatch($conversation);

        return 'suggested reply';
    }

    private function autoReply(Message $message, string $text): string
    {
        $conversation = $message->conversation;
        $account = $conversation->whatsappAccount;

        if (! $this->settings->autoReplyEnabled()) {
            return 'skipped auto_reply (disabled in settings)';
        }

        if (! $this->providers->isEnabled($account->provider)
            || ! $this->providers->for($account)->capabilities()->supportsAutoReply
            || $account->status !== DeviceStatus::Connected) {
            return 'skipped auto_reply (provider unavailable)';
        }

        $recentAutomated = $conversation->messages()
            ->where('is_automated', true)
            ->where('created_at', '>=', now()->subSeconds(self::AUTO_REPLY_COOLDOWN_SECONDS))
            ->exists();

        if ($recentAutomated) {
            return 'skipped auto_reply (cooldown)';
        }

        $this->queueOutgoingMessage->handle($conversation, null, $this->renderFor($message, $text), null, isAutomated: true);

        return 'auto-replied';
    }

    private function renderFor(Message $message, string $text): string
    {
        return $this->renderer->render($text, $this->renderer->valuesFor($message->contact, null));
    }

    /**
     * @param  list<string>  $taken
     */
    private function log(AutomationRule $rule, Message $message, AutomationExecutionStatus $status, array $taken, ?string $error = null): void
    {
        $rule->executions()->create([
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'status' => $status,
            'actions_taken' => $taken,
            'error' => $error,
        ]);
    }
}
