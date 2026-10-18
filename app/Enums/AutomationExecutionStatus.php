<?php

namespace App\Enums;

enum AutomationExecutionStatus: string
{
    case Executed = 'executed';
    case DryRun = 'dry_run';
    case RateLimited = 'rate_limited';
    case Failed = 'failed';
}
