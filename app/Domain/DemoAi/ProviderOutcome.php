<?php

namespace App\Domain\DemoAi;

final readonly class ProviderOutcome
{
    /**
     * Cost and uncertainty describe inference; counting billing is recorded separately on the attempt.
     *
     * @param  array<string, mixed>|null  $result
     * @param  array<string, int>|null  $usage
     */
    public function __construct(
        public ?array $result,
        public ?array $usage,
        public ?int $costMicroUsd,
        public ?string $failureCode,
        public bool $uncertain = false,
        public bool $retryable = false,
        public int $retryAfterSeconds = 30,
        public ?string $requestId = null,
        public ?string $returnedModel = null,
    ) {}
}
