<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\MessagingProviderName;
use Carbon\CarbonImmutable;
use Database\Factories\WhatsAppAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A linked WhatsApp device / number. Its id doubles as the gateway session id.
 *
 * @property string $id
 * @property string $name
 * @property MessagingProviderName $provider
 * @property DeviceStatus $status
 * @property string|null $phone_number
 * @property string|null $wa_jid
 * @property string|null $display_name
 * @property string|null $last_error
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('whatsapp_accounts')]
#[Fillable(['name', 'provider', 'status', 'phone_number', 'wa_jid', 'display_name', 'last_error', 'connected_at', 'status_changed_at'])]
class WhatsAppAccount extends Model
{
    /** @use HasFactory<WhatsAppAccountFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'created',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => MessagingProviderName::class,
            'status' => DeviceStatus::class,
            'connected_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DeviceConnectionEvent, $this>
     */
    public function connectionEvents(): HasMany
    {
        return $this->hasMany(DeviceConnectionEvent::class, 'whatsapp_account_id');
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'whatsapp_account_id');
    }

    public function sessionId(): string
    {
        return $this->id;
    }
}
