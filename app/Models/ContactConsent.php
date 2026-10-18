<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ContactConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $contact_id
 * @property string $scope
 * @property string $source
 * @property string|null $evidence
 * @property CarbonImmutable $consented_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $recorded_by_user_id
 */
#[Fillable(['contact_id', 'scope', 'source', 'evidence', 'consented_at', 'revoked_at', 'recorded_by_user_id'])]
class ContactConsent extends Model
{
    /** @use HasFactory<ContactConsentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
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
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
