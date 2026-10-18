<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WorkspaceSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspace_name' => ['required', 'string', 'max:100'],
            'business_hours.timezone' => ['required', 'timezone:all'],
            'business_hours.days' => ['required', 'array', 'min:1'],
            'business_hours.days.*' => ['integer', 'between:1,7', 'distinct'],
            'business_hours.start' => ['required', 'date_format:H:i'],
            'business_hours.end' => ['required', 'date_format:H:i', 'after:business_hours.start'],
            'auto_reply_enabled' => ['required', 'boolean'],
            'notify_on_new_conversation' => ['required', 'boolean'],
            'notify_on_failed_message' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['business_hours.end.after' => 'Closing time must be after opening time.'];
    }
}
