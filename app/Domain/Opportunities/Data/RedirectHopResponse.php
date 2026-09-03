<?php

namespace App\Domain\Opportunities\Data;

final readonly class RedirectHopResponse
{
    public function __construct(
        public int $status,
        public ?string $location,
    ) {}
}
