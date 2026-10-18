<?php

namespace App\Enums;

enum MessagingProviderName: string
{
    case Mock = 'mock';
    case UnofficialWeb = 'unofficial_web';
    case OfficialCloud = 'official_cloud';

    public function label(): string
    {
        return match ($this) {
            self::Mock => 'Mock (simulated)',
            self::UnofficialWeb => 'WhatsApp Web (unofficial, testing only)',
            self::OfficialCloud => 'WhatsApp Business Cloud API',
        };
    }
}
