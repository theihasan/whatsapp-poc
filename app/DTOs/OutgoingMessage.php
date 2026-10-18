<?php

namespace App\DTOs;

use App\Enums\MessageType;

final readonly class OutgoingMessage
{
    public function __construct(
        public string $idempotencyKey,
        public string $recipientJid,
        public MessageType $type,
        public ?string $body,
        public ?OutgoingAttachment $attachment = null,
    ) {}
}
