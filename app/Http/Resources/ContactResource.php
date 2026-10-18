<?php

namespace App\Http\Resources;

use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'phone_number' => $this->phone_number,
            'wa_jid' => $this->wa_jid,
            'source' => $this->source->value,
            'notes' => $this->notes,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->all()),
            'consents' => $this->whenLoaded('consents', fn () => $this->consents->map(fn (ContactConsent $consent): array => [
                'id' => $consent->id,
                'scope' => $consent->scope,
                'source' => $consent->source,
                'evidence' => $consent->evidence,
                'consented_at' => $consent->consented_at->toIso8601String(),
                'revoked_at' => $consent->revoked_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
