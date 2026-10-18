<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Automation\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAutomations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(public Message $message)
    {
        $this->onQueue('automations');
    }

    public function uniqueId(): string
    {
        return $this->message->id;
    }

    public function handle(AutomationEngine $engine): void
    {
        if ($this->message->automationExecutions()->exists()) {
            return;
        }

        $engine->run($this->message);
    }
}
