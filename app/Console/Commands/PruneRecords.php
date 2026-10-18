<?php

namespace App\Console\Commands;

use App\Enums\GatewayEventStatus;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\DeviceConnectionEvent;
use App\Models\GatewayEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the retention policy in docs/database.md. Messages, contacts and consent
 * records are business data and are never pruned automatically.
 */
#[Signature('app:prune-records')]
#[Description('Delete operational records past their retention window')]
class PruneRecords extends Command
{
    public function handle(): int
    {
        $retention = (array) config('messaging.retention');

        $counts = [
            'gateway_events' => GatewayEvent::query()
                ->whereIn('status', [GatewayEventStatus::Processed, GatewayEventStatus::Ignored])
                ->where('created_at', '<', now()->subDays((int) $retention['gateway_events_days']))
                ->delete(),
            'automation_executions' => AutomationExecution::query()
                ->where('created_at', '<', now()->subDays((int) $retention['automation_executions_days']))
                ->delete(),
            'device_connection_events' => DeviceConnectionEvent::query()
                ->where('occurred_at', '<', now()->subDays((int) $retention['device_connection_events_days']))
                ->delete(),
            'audit_logs' => AuditLog::query()
                ->where('created_at', '<', now()->subDays((int) $retention['audit_logs_days']))
                ->delete(),
            'notifications' => DB::table('notifications')
                ->whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays((int) $retention['read_notifications_days']))
                ->delete(),
        ];

        foreach ($counts as $table => $deleted) {
            $this->line("{$table}: {$deleted}");
        }

        return self::SUCCESS;
    }
}
