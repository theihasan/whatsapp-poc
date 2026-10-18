<?php

namespace App\Http\Resources;

use App\Models\WhatsAppAccount;
use App\Services\Messaging\MessagingProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WhatsAppAccount
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registry = app(MessagingProviderRegistry::class);
        $enabled = $registry->isEnabled($this->provider);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'provider_enabled' => $enabled,
            'capabilities' => $enabled ? $registry->resolve($this->provider)->capabilities()->toArray() : null,
            'status' => $this->status->value,
            'phone_number' => $this->phone_number,
            'display_name' => $this->display_name,
            'last_error' => $this->last_error,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
