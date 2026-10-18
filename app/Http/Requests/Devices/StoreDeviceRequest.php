<?php

namespace App\Http\Requests\Devices;

use App\Enums\MessagingProviderName;
use App\Services\Messaging\MessagingProviderRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $enabled = array_map(
            fn (MessagingProviderName $name): string => $name->value,
            app(MessagingProviderRegistry::class)->enabledProviders(),
        );

        return [
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::in($enabled)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'That provider is not enabled on this server.',
        ];
    }
}
