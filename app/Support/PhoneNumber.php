<?php

namespace App\Support;

/**
 * Minimal E.164 handling without a phone library (no new dependency approved): strips
 * formatting characters and requires an explicit "+" country code.
 */
class PhoneNumber
{
    public static function normalize(string $input): ?string
    {
        $candidate = preg_replace('/[\s().\-]/', '', trim($input)) ?? '';

        if (str_starts_with($candidate, '00')) {
            $candidate = '+'.substr($candidate, 2);
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $candidate) === 1 ? $candidate : null;
    }

    public static function toJid(string $e164): string
    {
        return ltrim($e164, '+').'@s.whatsapp.net';
    }
}
