<?php

namespace App\Domain\Opportunities\Data;

final readonly class ResolvedJobDestination
{
    public function __construct(
        public string $externalJobId,
        public string $canonicalUrl,
    ) {}
}
