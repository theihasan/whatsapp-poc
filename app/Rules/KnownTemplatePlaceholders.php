<?php

namespace App\Rules;

use App\Services\Templates\TemplateRenderer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class KnownTemplatePlaceholders implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unknown = app(TemplateRenderer::class)->unknownPlaceholders((string) $value);

        if ($unknown !== []) {
            $fail('Unknown placeholder: {{'.implode('}}, {{', $unknown).'}}.');
        }
    }
}
