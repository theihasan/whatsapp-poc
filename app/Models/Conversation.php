<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $whatsapp_account_id
 * @property string $contact_id
 * @property ConversationStatus $status
 * @property int|null $assigned_user_id
 * @property CarbonImmutable|null $last_message_at
 * @property string|null $last_message_preview
 * @property int $unread_count
 * @property string|null $suggested_reply
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['whatsapp_account_id', 'contact_id', 'status', 'assigned_user_id', 'last_message_at', 'last_message_preview', 'unread_count', 'suggested_reply'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'unread_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function filter(Builder $query, ?string $filter, User $user): void
    {
        match ($filter) {
            'unread' => $query->where('unread_count', '>', 0),
            'open' => $query->where('status', ConversationStatus::Open),
            'closed' => $query->where('status', ConversationStatus::Closed),
            'mine' => $query->where('assigned_user_id', $user->id),
            'direct' => $query->whereHas('contact', fn (Builder $contact) => $contact->where('is_group', false)),
            'groups' => $query->whereHas('contact', fn (Builder $contact) => $contact->where('is_group', true)),
            default => null,
        };
    }
}
