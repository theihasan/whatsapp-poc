<?php

namespace App\Http\Requests\Gateway;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GatewayEventRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'eventId' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:64', 'regex:/^[a-z]+(\.[a-z_]+)+$/'],
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'sessionId' => ['required', 'string', 'max:64'],
            'occurredAt' => ['required', 'date'],
            'payload' => ['present', 'array'],
        ];
    }
}
