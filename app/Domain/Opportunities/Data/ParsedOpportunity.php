<?php

namespace App\Domain\Opportunities\Data;

use App\Domain\Opportunities\Enums\ContractType;
use App\Domain\Opportunities\Enums\OpportunityProvider;
use Carbon\CarbonImmutable;

final readonly class ParsedOpportunity
{
    /** @param list<string> $skills */
    public function __construct(
        public OpportunityProvider $provider,
        public string $sourceMessageId,
        public string $externalJobId,
        public string $canonicalUrl,
        public string $title,
        public ContractType $contractType,
        public ?string $hourlyMin,
        public ?string $hourlyMax,
        public string $currency,
        public ?string $estimatedDuration,
        public ?CarbonImmutable $postedOn,
        public ?string $excerpt,
        public array $skills,
        public int $hiddenSkillCount,
        public ?bool $paymentVerified,
        public ?string $clientRating,
        public ?string $clientSpendUsd,
        public bool $clientSpendApproximate,
        public ?string $clientCountry,
        public string $templateFingerprint,
    ) {}
}
