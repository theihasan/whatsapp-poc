<?php

namespace App\DTOs;

final readonly class ProviderHealth
{
    public function __construct(
        public bool $healthy,
        public string $summary,
    ) {}

    /**
     * @return array{healthy: bool, summary: string}
     */
    public function toArray(): array
    {
        return ['healthy' => $this->healthy, 'summary' => $this->summary];
    }
}
