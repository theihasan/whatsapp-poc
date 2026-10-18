<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceConnectionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $whatsapp_account_id
 * @property DeviceStatus|null $from_status
 * @property DeviceStatus $to_status
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[WithoutTimestamps]
#[Fillable(['whatsapp_account_id', 'from_status', 'to_status', 'reason', 'occurred_at'])]
class DeviceConnectionEvent extends Model
{
    /** @use HasFactory<DeviceConnectionEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => DeviceStatus::class,
            'to_status' => DeviceStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
