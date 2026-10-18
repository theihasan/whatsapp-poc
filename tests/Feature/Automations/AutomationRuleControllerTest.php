<?php

use App\Models\AutomationRule;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @return array<string, mixed>
 */
function rulePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Pricing',
        'is_enabled' => true,
        'is_dry_run' => true,
        'priority' => 10,
        'max_runs_per_conversation_per_hour' => 1,
        'conditions' => ['keywords' => [' Price ', 'price'], 'business_hours' => null, 'tag_ids' => []],
        'actions' => [['type' => 'suggest_reply', 'text' => 'Hi {{contact_name}}']],
    ], $overrides);
}

it('creates a rule with normalized keywords', function () {
    $this->post(route('automations.store'), rulePayload())->assertRedirect();

    expect(AutomationRule::query()->sole()->conditions['keywords'])->toBe(['price']);
});

it('requires at least one condition', function () {
    $this->post(route('automations.store'), rulePayload(['conditions' => ['keywords' => [], 'business_hours' => null, 'tag_ids' => []]]))
        ->assertSessionHasErrors(['conditions' => 'Add at least one condition so the rule does not fire on every message.']);
});

it('requires a user for assignment and text for replies', function () {
    $this->post(route('automations.store'), rulePayload(['actions' => [['type' => 'assign'], ['type' => 'auto_reply']]]))
        ->assertSessionHasErrors(['actions.0.user_id', 'actions.1.text']);
});

it('rejects unknown placeholders in reply text', function () {
    $this->post(route('automations.store'), rulePayload(['actions' => [['type' => 'auto_reply', 'text' => '{{secret}}']]]))
        ->assertSessionHasErrors('actions.0.text');
});

it('toggles a rule', function () {
    $rule = AutomationRule::factory()->create(['is_enabled' => true]);

    $this->patch(route('automations.toggle', $rule), ['is_enabled' => false])->assertRedirect();

    expect($rule->fresh()->is_enabled)->toBeFalse();
});
