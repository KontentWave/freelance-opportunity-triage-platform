<?php

namespace App\Domain\Opportunities\Contracts;

interface HostAddressResolver
{
    /** @return list<string> */
    public function resolve(string $host, int $timeoutMilliseconds): array;
}
