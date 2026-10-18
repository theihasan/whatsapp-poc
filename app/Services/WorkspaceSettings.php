<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to workspace-level settings stored in the `settings` table.
 *
 * @phpstan-type BusinessHours array{timezone: string, days: list<int>, start: string, end: string}
 */
class WorkspaceSettings
{
    private const CACHE_KEY = 'workspace-settings';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'workspace_name' => 'My Workspace',
        'business_hours' => ['timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00'],
        'auto_reply_enabled' => false,
        'notify_on_new_conversation' => true,
        'notify_on_failed_message' => true,
    ];

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());

        return [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public function workspaceName(): string
    {
        return (string) $this->get('workspace_name');
    }

    public function autoReplyEnabled(): bool
    {
        return (bool) $this->get('auto_reply_enabled');
    }

    /**
     * @return BusinessHours
     */
    public function businessHours(): array
    {
        /** @var BusinessHours $hours */
        $hours = $this->get('business_hours');

        return $hours;
    }

    public function isWithinBusinessHours(CarbonInterface $moment): bool
    {
        $hours = $this->businessHours();
        $local = $moment->setTimezone($hours['timezone']);
        $time = $local->format('H:i');

        return in_array($local->dayOfWeekIso, $hours['days'], true)
            && $time >= $hours['start']
            && $time < $hours['end'];
    }
}
