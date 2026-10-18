<?php

namespace App\Models;

use App\Enums\ContactSource;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WhatsApp counterpart. `wa_jid` is the preferred protocol identifier (phone-number JID
 * when known, otherwise the LID); `wa_lid` keeps the privacy LID so either form matches.
 * `phone_number` is only set when WhatsApp actually revealed it.
 *
 * @property string $id
 * @property string|null $name
 * @property string|null $wa_jid
 * @property string|null $wa_lid
 * @property string|null $phone_number
 * @property ContactSource $source
 * @property string|null $notes
 * @property string|null $avatar_path
 * @property CarbonImmutable|null $avatar_checked_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'wa_jid', 'wa_lid', 'phone_number', 'source', 'notes', 'avatar_path', 'avatar_checked_at', 'last_activity_at'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ContactSource::class,
            'last_activity_at' => 'datetime',
            'avatar_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return HasMany<ContactConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(ContactConsent::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public const AVATAR_REFRESH_DAYS = 7;

    public function avatarIsStale(): bool
    {
        return $this->avatar_checked_at === null || $this->avatar_checked_at->lt(now()->subDays(self::AVATAR_REFRESH_DAYS));
    }

    public function displayName(): string
    {
        return $this->name ?? $this->phone_number ?? $this->wa_jid ?? 'Unknown contact';
    }

    public function hasActiveConsent(string $scope): bool
    {
        return $this->consents()->active()->where('scope', $scope)->exists();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('phone_number', 'like', $like)
                ->orWhere('wa_jid', 'like', $like);
        });
    }
}
