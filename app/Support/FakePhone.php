<?php

namespace App\Support;

/**
 * Privacy-safe numbers from the NANP fictional range 555-0100…0199, used by the mock
 * provider and seed data. Never generates a real subscriber number.
 */
class FakePhone
{
    public static function number(): string
    {
        return sprintf('+1%03d55501%02d', random_int(201, 989), random_int(0, 99));
    }

    public static function jid(string $phoneNumber): string
    {
        return ltrim($phoneNumber, '+').'@s.whatsapp.net';
    }
}
