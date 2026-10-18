<?php

namespace App\Services\Reporting;

use App\Enums\ConversationStatus;
use App\Enums\DeviceStatus;
use App\Enums\GatewayEventStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessagingProviderName;
use App\Models\Conversation;
use App\Models\GatewayEvent;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Dashboard aggregates. Every query is a count/group over indexed columns, bounded to a
 * recent window, and the whole result is cached briefly so page loads never scan tables.
 */
class DashboardMetrics
{
    public const CACHE_SECONDS = 60;

    public const ACTIVITY_DAYS = 14;

    public function __construct(private MessagingProviderRegistry $providers) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return Cache::remember('dashboard:summary', self::CACHE_SECONDS, fn (): array => $this->compute());
    }

    public function forget(): void
    {
        Cache::forget('dashboard:summary');
        Cache::forget('dashboard:health');
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $since = CarbonImmutable::now()->subDays(self::ACTIVITY_DAYS - 1)->startOfDay();
        $weekAgo = CarbonImmutable::now()->subDays(7);

        $messageCounts = Message::query()
            ->where('created_at', '>=', $weekAgo)
            ->toBase()
            ->selectRaw('direction, count(*) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $deviceCounts = WhatsAppAccount::query()
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'generated_at' => now()->toIso8601String(),
            'simulated' => WhatsAppAccount::query()->where('provider', MessagingProviderName::Mock)->exists(),
            'devices' => [
                'connected' => (int) ($deviceCounts[DeviceStatus::Connected->value] ?? 0),
                'total' => (int) $deviceCounts->sum(),
            ],
            'conversations' => [
                'active' => Conversation::query()->where('status', ConversationStatus::Open)->where('last_message_at', '>=', $weekAgo)->count(),
                'unread' => Conversation::query()->where('unread_count', '>', 0)->count(),
                'unassigned_open' => Conversation::query()->where('status', ConversationStatus::Open)->whereNull('assigned_user_id')->count(),
            ],
            'messages_7d' => [
                'incoming' => (int) ($messageCounts[MessageDirection::Incoming->value] ?? 0),
                'outgoing' => (int) ($messageCounts[MessageDirection::Outgoing->value] ?? 0),
                'failed' => Message::query()->where('status', MessageStatus::Failed)->where('created_at', '>=', $weekAgo)->count(),
                'unknown' => Message::query()->where('status', MessageStatus::Unknown)->where('created_at', '>=', $weekAgo)->count(),
            ],
            'activity' => $this->activity($since),
            'workload' => $this->workload(),
            'gateway_events' => [
                'pending' => GatewayEvent::query()->where('status', GatewayEventStatus::Pending)->count(),
                'failed' => GatewayEvent::query()->where('status', GatewayEventStatus::Failed)->count(),
            ],
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    /**
     * Messages per day and direction, zero-filled.
     *
     * @return list<array{date: string, incoming: int, outgoing: int}>
     */
    private function activity(CarbonImmutable $since): array
    {
        $rows = Message::query()
            ->where('created_at', '>=', $since)
            ->toBase()
            ->selectRaw('DATE(created_at) as day, direction, count(*) as total')
            ->groupBy('day', 'direction')
            ->get();

        $days = [];

        for ($date = $since; $date->lte(now()); $date = $date->addDay()) {
            $days[$date->toDateString()] = ['date' => $date->toDateString(), 'incoming' => 0, 'outgoing' => 0];
        }

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $direction = (string) $row->direction;

            if (isset($days[$day]) && in_array($direction, ['incoming', 'outgoing'], true)) {
                $days[$day][$direction] = (int) $row->total;
            }
        }

        return array_values($days);
    }

    /**
     * Open conversations per assignee.
     *
     * @return list<array{name: string, open: int}>
     */
    private function workload(): array
    {
        $counts = Conversation::query()
            ->where('status', ConversationStatus::Open)
            ->whereNotNull('assigned_user_id')
            ->toBase()
            ->selectRaw('assigned_user_id, count(*) as total')
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id');

        return array_values(User::query()
            ->whereKey($counts->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['name' => $user->name, 'open' => (int) $counts[$user->id]])
            ->sortByDesc('open')
            ->all());
    }

    /**
     * Live dependency checks, cached briefly (the gateway check is an HTTP call).
     *
     * @return list<array{name: string, healthy: bool, summary: string}>
     */
    public function health(): array
    {
        return Cache::remember('dashboard:health', 30, function (): array {
            $checks = [
                ['name' => 'Database', ...$this->probe(fn () => DB::select('select 1'))],
                ['name' => 'Redis', ...$this->probe(fn () => Redis::connection()->ping())],
            ];

            foreach ($this->providers->enabledProviders() as $name) {
                $checks[] = ['name' => $name->label(), ...$this->providers->resolve($name)->health()->toArray()];
            }

            return $checks;
        });
    }

    /**
     * @return array{healthy: bool, summary: string}
     */
    private function probe(callable $check): array
    {
        try {
            $check();

            return ['healthy' => true, 'summary' => 'Reachable'];
        } catch (Throwable $exception) {
            return ['healthy' => false, 'summary' => class_basename($exception).': unreachable'];
        }
    }
}
