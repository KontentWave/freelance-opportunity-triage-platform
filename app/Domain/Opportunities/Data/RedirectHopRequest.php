<?php

namespace App\Domain\Opportunities\Data;

final readonly class RedirectHopRequest
{
    public function __construct(
        public string $url,
        public string $host,
        public string $address,
        public int $connectTimeoutMilliseconds,
        public int $timeoutMilliseconds,
        public int $maximumHeaderBytes,
    ) {}
}
