<?php

namespace Database\Factories;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationExecution>
 */
class AutomationExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'automation_rule_id' => AutomationRule::factory(),
            'status' => AutomationExecutionStatus::Executed,
            'actions_taken' => ['suggest_reply'],
        ];
    }
}
