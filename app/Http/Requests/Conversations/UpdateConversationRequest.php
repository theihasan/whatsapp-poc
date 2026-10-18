<?php

namespace App\Http\Requests\Conversations;

use App\Enums\ConversationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConversationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }
}
