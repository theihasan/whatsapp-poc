<?php

namespace App\Http\Requests\Automations;

use App\Rules\KnownTemplatePlaceholders;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AutomationRuleRequest extends FormRequest
{
    public const ACTION_TYPES = ['assign', 'add_tag', 'notify', 'suggest_reply', 'auto_reply'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
            'is_dry_run' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_runs_per_conversation_per_hour' => ['required', 'integer', 'min:1', 'max:60'],
            'conditions' => ['required', 'array'],
            'conditions.keywords' => ['array', 'max:20'],
            'conditions.keywords.*' => ['string', 'min:2', 'max:50'],
            'conditions.business_hours' => ['nullable', 'in:inside,outside'],
            'conditions.tag_ids' => ['array', 'max:10'],
            'conditions.tag_ids.*' => ['integer', Rule::exists('tags', 'id')],
            'actions' => ['required', 'array', 'min:1', 'max:5'],
            'actions.*.type' => ['required', Rule::in(self::ACTION_TYPES)],
            'actions.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), 'required_if:actions.*.type,assign'],
            'actions.*.tag_id' => ['nullable', 'integer', Rule::exists('tags', 'id'), 'required_if:actions.*.type,add_tag'],
            'actions.*.text' => ['nullable', 'string', 'max:4096', new KnownTemplatePlaceholders, 'required_if:actions.*.type,suggest_reply,auto_reply'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $conditions = (array) $this->input('conditions', []);

                if (empty($conditions['keywords']) && empty($conditions['business_hours']) && empty($conditions['tag_ids'])) {
                    $validator->errors()->add('conditions', 'Add at least one condition so the rule does not fire on every message.');
                }
            },
        ];
    }

    /**
     * @return array{name: string, is_enabled: bool, is_dry_run: bool, priority: int, max_runs_per_conversation_per_hour: int, conditions: array<string, mixed>, actions: list<array<string, mixed>>}
     */
    public function rule(): array
    {
        /** @var array{name: string, is_enabled: bool, is_dry_run: bool, priority: int, max_runs_per_conversation_per_hour: int, conditions: array{keywords?: list<string>, business_hours?: string|null, tag_ids?: list<int>}, actions: list<array{type: string, user_id?: int|null, tag_id?: int|null, text?: string|null}>} $data */
        $data = $this->validated();

        $data['conditions'] = [
            'keywords' => array_values(array_unique(array_map(fn (string $keyword): string => mb_strtolower(trim($keyword)), $data['conditions']['keywords'] ?? []))),
            'business_hours' => $data['conditions']['business_hours'] ?? null,
            'tag_ids' => array_map(intval(...), $data['conditions']['tag_ids'] ?? []),
        ];
        $data['actions'] = array_map(fn (array $action): array => array_filter([
            'type' => $action['type'],
            'user_id' => isset($action['user_id']) ? (int) $action['user_id'] : null,
            'tag_id' => isset($action['tag_id']) ? (int) $action['tag_id'] : null,
            'text' => $action['text'] ?? null,
        ], fn (mixed $value): bool => $value !== null), $data['actions']);

        return $data;
    }
}
