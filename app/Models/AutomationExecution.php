<?php

namespace App\Models;

use App\Enums\AutomationExecutionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AutomationExecutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automation_rule_id
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property AutomationExecutionStatus $status
 * @property list<string>|null $actions_taken
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
#[Fillable(['automation_rule_id', 'conversation_id', 'message_id', 'status', 'actions_taken', 'error'])]
class AutomationExecution extends Model
{
    /** @use HasFactory<AutomationExecutionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AutomationExecutionStatus::class,
            'actions_taken' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
