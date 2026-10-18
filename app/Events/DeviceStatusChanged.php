<?php

namespace App\Events;

use App\Models\WhatsAppAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public WhatsAppAccount $account) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('devices')];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->account->id,
            'status' => $this->account->status->value,
            'phone_number' => $this->account->phone_number,
            'display_name' => $this->account->display_name,
            'last_error' => $this->account->last_error,
            'status_changed_at' => $this->account->status_changed_at?->toIso8601String(),
        ];
    }
}
