<?php

namespace App\Models;

use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_enabled
 * @property bool $is_dry_run
 * @property int $priority
 * @property string $trigger
 * @property array{keywords?: list<string>, business_hours?: 'inside'|'outside'|null, tag_ids?: list<int>} $conditions
 * @property list<array{type: string, user_id?: int|null, tag_id?: int|null, text?: string|null}> $actions
 * @property int $max_runs_per_conversation_per_hour
 */
#[Fillable(['name', 'is_enabled', 'is_dry_run', 'priority', 'trigger', 'conditions', 'actions', 'max_runs_per_conversation_per_hour'])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_dry_run' => 'boolean',
            'priority' => 'integer',
            'conditions' => 'array',
            'actions' => 'array',
            'max_runs_per_conversation_per_hour' => 'integer',
        ];
    }

    /**
     * @return HasMany<AutomationExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }
}
