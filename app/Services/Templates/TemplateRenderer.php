<?php

namespace App\Services\Templates;

use App\Models\Contact;
use App\Models\User;
use App\Services\WorkspaceSettings;

/**
 * Renders local text templates. Substitution is a single pass, so placeholder syntax
 * inside a value (e.g. a contact named "{{company_name}}") is never expanded, and
 * control characters are stripped from values. Output is plain text for WhatsApp; the
 * UI escapes it like any other message body.
 */
class TemplateRenderer
{
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /**
     * @var array<string, string>
     */
    public const VARIABLES = [
        'contact_name' => 'Contact name',
        'contact_phone' => 'Contact phone number',
        'company_name' => 'Workspace name',
        'agent_name' => 'Your name',
        'today' => "Today's date",
    ];

    /**
     * @var array<string, string>
     */
    public const SAMPLE = [
        'contact_name' => 'Alex Doe',
        'contact_phone' => '+1 202 555 0123',
        'company_name' => 'Acme Support',
        'agent_name' => 'Sam',
        'today' => '18 October 2026',
    ];

    public function __construct(private WorkspaceSettings $settings) {}

    /**
     * @return list<string> placeholders used in the body that are not supported
     */
    public function unknownPlaceholders(string $body): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $body, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys(self::VARIABLES))));
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function render(string $body, array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER_PATTERN, function (array $match) use ($values): string {
            if (! array_key_exists($match[1], self::VARIABLES)) {
                return $match[0];
            }

            return self::clean($values[$match[1]] ?? '');
        }, $body);
    }

    /**
     * @return array<string, string|null>
     */
    public function valuesFor(?Contact $contact, ?User $agent): array
    {
        return [
            'contact_name' => $contact->name ?? 'there',
            'contact_phone' => $contact?->phone_number,
            'company_name' => $this->settings->workspaceName(),
            'agent_name' => $agent?->name,
            'today' => now()->translatedFormat('j F Y'),
        ];
    }

    private static function clean(?string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value));
    }
}
