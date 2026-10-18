<?php

namespace App\DTOs;

final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $usesQrPairing,
        public bool $supportsMedia,
        public bool $supportsAutoReply,
        public bool $supportsBulkMessaging,
        public bool $isSimulated,
        public bool $idempotentSends,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
