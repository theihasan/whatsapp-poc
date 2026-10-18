<?php

namespace App\DTOs;

use App\Enums\SendOutcome;

final readonly class SendResult
{
    public function __construct(
        public SendOutcome $outcome,
        public ?string $externalId = null,
        public ?string $error = null,
    ) {}

    public static function accepted(string $externalId): self
    {
        return new self(SendOutcome::Accepted, $externalId);
    }

    public static function rejected(string $error): self
    {
        return new self(SendOutcome::Rejected, error: $error);
    }

    public static function unknown(string $error): self
    {
        return new self(SendOutcome::Unknown, error: $error);
    }
}
