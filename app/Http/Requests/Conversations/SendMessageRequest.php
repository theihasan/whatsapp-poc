<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:4096', 'required_without:attachment'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:'.implode(',', (array) config('messaging.attachments.mimes')),
                'max:'.config('messaging.attachments.max_kilobytes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Write a message or attach a file.',
            'attachment.mimes' => 'Only images (JPG, PNG, WebP) and documents (PDF, Word, Excel, text) can be sent.',
        ];
    }
}
