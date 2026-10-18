<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConsentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:marketing,service,transactional'],
            'source' => ['required', 'in:web_form,in_person,whatsapp_message,phone_call,email,other'],
            'evidence' => ['required', 'string', 'max:1000'],
            'consented_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidence.required' => 'Record how the opt-in was captured (form id, message, call note…).',
        ];
    }
}
