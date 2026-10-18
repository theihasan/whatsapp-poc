<?php

namespace Database\Factories;

use App\Models\AutomationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'is_enabled' => true,
            'is_dry_run' => false,
            'priority' => 100,
            'trigger' => 'incoming_message',
            'conditions' => ['keywords' => ['pricing']],
            'actions' => [['type' => 'suggest_reply', 'text' => 'Our pricing page is on the website.']],
            'max_runs_per_conversation_per_hour' => 1,
        ];
    }
}
