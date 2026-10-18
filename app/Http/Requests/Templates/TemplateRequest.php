<?php

namespace App\Http\Requests\Templates;

use App\Enums\TemplateStatus;
use App\Rules\KnownTemplatePlaceholders;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TemplateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:64'],
            'body' => ['required', 'string', 'max:4096', new KnownTemplatePlaceholders],
            'status' => ['required', Rule::enum(TemplateStatus::class)],
        ];
    }
}
