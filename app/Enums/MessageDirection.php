<?php

namespace App\Enums;

enum MessageDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
