<?php

namespace App\DTOs;

final readonly class OutgoingAttachment
{
    public function __construct(
        public string $fileName,
        public string $mimeType,
        public string $base64Contents,
    ) {}
}
