<?php

namespace App\Enums;

enum ContactSource: string
{
    case Manual = 'manual';
    case IncomingMessage = 'incoming_message';
}
