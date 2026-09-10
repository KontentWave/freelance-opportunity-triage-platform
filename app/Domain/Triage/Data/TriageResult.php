<?php

namespace App\Domain\Triage\Data;

use App\Domain\Triage\Enums\TriageRecommendation;

final readonly class TriageResult
{
    /**
     * @param  list<array{rule: string, state: string, points: int, maximum_points: int, reason_code: string, explanation: string}>  $contributions
     * @param  list<string>  $missingFields
     * @param  list<string>  $hardExclusions
     */
    public function __construct(
        public TriageRecommendation $recommendation,
        public int $score,
        public array $contributions,
        public array $missingFields,
        public array $hardExclusions,
        public string $decisionReasonCode,
        public string $decisionExplanation,
        public bool $manualReviewRequired = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'recommendation' => $this->recommendation->value,
            'score' => $this->score,
            'contributions' => $this->contributions,
            'missing_fields' => $this->missingFields,
            'hard_exclusions' => $this->hardExclusions,
            'decision_reason_code' => $this->decisionReasonCode,
            'decision_explanation' => $this->decisionExplanation,
            'manual_review_required' => $this->manualReviewRequired,
        ];
    }
}
