<?php

namespace Tests\Unit\Domain\Triage;

use const JSON_THROW_ON_ERROR;

use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Data\TriageInput;
use App\Domain\Triage\Enums\TriageRecommendation;
use App\Domain\Triage\OpportunityScorer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

final class OpportunityScorerTest extends TestCase
{
    #[Test]
    public function it_explains_a_promising_opportunity_with_four_contributions(): void
    {
        $result = (new OpportunityScorer)->evaluate($this->completeInput(), $this->profile());

        $this->assertSame(TriageRecommendation::Apply, $result->recommendation);
        $this->assertSame(100, $result->score);
        $this->assertSame(['skill_match', 'rate', 'payment_verified', 'client_rating'], array_column($result->contributions, 'rule'));
        $this->assertSame(['matched', 'matched', 'matched', 'matched'], array_column($result->contributions, 'state'));
        $this->assertSame([40, 30, 20, 10], array_column($result->contributions, 'points'));
        $this->assertSame([], $result->missingFields);
        $this->assertSame([], $result->hardExclusions);
        $this->assertSame('decision.score_apply', $result->decisionReasonCode);
        $this->assertTrue($result->manualReviewRequired);
    }

    #[Test]
    public function it_applies_a_known_rate_exclusion_before_missing_data(): void
    {
        $input = TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'USD',
            'hourly_max' => '19.99',
            'skills' => [],
            'hidden_skill_count' => 0,
            'payment_verified' => null,
            'client_rating' => null,
        ]);

        $result = (new OpportunityScorer)->evaluate($input, $this->profile());

        $this->assertSame(TriageRecommendation::Skip, $result->recommendation);
        $this->assertSame(['rate.maximum_below_minimum'], $result->hardExclusions);
        $this->assertSame(['client_rating', 'payment_verified', 'skills'], $result->missingFields);
        $this->assertSame('decision.below_rate_floor', $result->decisionReasonCode);
    }

    #[Test]
    public function it_keeps_unknown_signals_as_maybe_without_a_hard_exclusion(): void
    {
        $input = TriageInput::fromArray([
            'contract_type' => 'fixed',
            'currency' => 'EUR',
            'hourly_max' => null,
            'skills' => ['django'],
            'hidden_skill_count' => 0,
            'payment_verified' => true,
            'client_rating' => '4.80',
        ]);

        $result = (new OpportunityScorer)->evaluate($input, $this->profile());

        $this->assertSame(TriageRecommendation::Maybe, $result->recommendation);
        $this->assertSame(70, $result->score);
        $this->assertSame(['contract_type', 'currency', 'hourly_max'], $result->missingFields);
        $this->assertSame('rate.unknown', $result->contributions[1]['reason_code']);
        $this->assertSame('decision.incomplete_data', $result->decisionReasonCode);
    }

    #[Test]
    public function it_does_not_skip_only_because_visible_skills_do_not_match(): void
    {
        $input = TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'usd',
            'hourly_max' => '20.00',
            'skills' => ['Laravel'],
            'hidden_skill_count' => 0,
            'payment_verified' => true,
            'client_rating' => '4.50',
        ]);

        $result = (new OpportunityScorer)->evaluate($input, $this->profile());

        $this->assertSame(TriageRecommendation::Maybe, $result->recommendation);
        $this->assertSame(60, $result->score);
        $this->assertSame('not_matched', $result->contributions[0]['state']);
        $this->assertSame('skills.no_match', $result->contributions[0]['reason_code']);
        $this->assertSame([], $result->missingFields);
        $this->assertSame('decision.score_maybe', $result->decisionReasonCode);
    }

    #[Test]
    public function it_treats_hidden_nonmatching_skills_and_an_unrated_client_as_unknown(): void
    {
        $input = TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'USD',
            'hourly_max' => '25.00',
            'skills' => ['Laravel'],
            'hidden_skill_count' => 2,
            'payment_verified' => false,
            'client_rating' => '0.00',
        ]);

        $result = (new OpportunityScorer)->evaluate($input, $this->profile());

        $this->assertSame(TriageRecommendation::Maybe, $result->recommendation);
        $this->assertSame(['client_rating', 'skills'], $result->missingFields);
        $this->assertSame(['skills.unknown', 'rate.meets_floor', 'payment.unverified', 'rating.unknown'], array_column($result->contributions, 'reason_code'));
    }

    #[Test]
    public function it_applies_score_thresholds_after_exclusions_and_unknowns(): void
    {
        $applyAtThreshold = (new OpportunityScorer)->evaluate(TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'USD',
            'hourly_max' => '20.00',
            'skills' => ['django'],
            'hidden_skill_count' => 0,
            'payment_verified' => false,
            'client_rating' => '4.00',
        ]), $this->profile());
        $thresholdProfile = ScoringProfile::fromArray([
            ...$this->profile()->definition,
            'weights' => [
                'skill_match' => 34,
                'rate' => 1,
                'payment_verified' => 32,
                'client_rating' => 33,
            ],
        ]);
        $skipAtThreshold = (new OpportunityScorer)->evaluate(TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'USD',
            'hourly_max' => '10.00',
            'skills' => ['django'],
            'hidden_skill_count' => 0,
            'payment_verified' => false,
            'client_rating' => '4.00',
        ]), ScoringProfile::fromArray([
            ...$thresholdProfile->definition,
            'minimum_hourly_usd' => '10.00',
        ]));
        $belowSkipThreshold = (new OpportunityScorer)->evaluate(TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'USD',
            'hourly_max' => '10.00',
            'skills' => ['django'],
            'hidden_skill_count' => 0,
            'payment_verified' => false,
            'client_rating' => '4.00',
        ]), ScoringProfile::fromArray([
            ...$thresholdProfile->definition,
            'minimum_hourly_usd' => '10.00',
            'weights' => [
                'skill_match' => 33,
                'rate' => 1,
                'payment_verified' => 33,
                'client_rating' => 33,
            ],
        ]));

        $this->assertSame(70, $applyAtThreshold->score);
        $this->assertSame(TriageRecommendation::Apply, $applyAtThreshold->recommendation);
        $this->assertSame(35, $skipAtThreshold->score);
        $this->assertSame(TriageRecommendation::Maybe, $skipAtThreshold->recommendation);
        $this->assertSame(34, $belowSkipThreshold->score);
        $this->assertSame(TriageRecommendation::Skip, $belowSkipThreshold->recommendation);
    }

    #[Test]
    public function it_replays_the_committed_golden_scoring_cases(): void
    {
        $fixture = file_get_contents(__DIR__.'/../../../Fixtures/Triage/scoring-golden-cases.json');
        $this->assertIsString($fixture);

        $cases = json_decode($fixture, true, flags: JSON_THROW_ON_ERROR);

        foreach ($cases as $case) {
            $result = (new OpportunityScorer)->evaluate(TriageInput::fromArray($case['input']), $this->profile());

            $this->assertSame($case['expected_result'], $result->toArray(), $case['name']);
        }
    }

    private function completeInput(): TriageInput
    {
        return TriageInput::fromArray([
            'contract_type' => 'hourly',
            'currency' => 'usd',
            'hourly_max' => '40.00',
            'skills' => ['Quality   Assurance', 'Laravel'],
            'hidden_skill_count' => 0,
            'payment_verified' => true,
            'client_rating' => '4.90',
        ]);
    }

    private function profile(): ScoringProfile
    {
        return ScoringProfile::fromArray([
            'schema_version' => 1,
            'label' => 'Synthetic demo profile',
            'purpose' => 'demo',
            'minimum_hourly_usd' => '20.00',
            'preferred_skills' => ['django', 'project management', 'quality assurance'],
            'minimum_client_rating' => '4.50',
            'weights' => [
                'skill_match' => 40,
                'rate' => 30,
                'payment_verified' => 20,
                'client_rating' => 10,
            ],
            'thresholds' => ['skip_below' => 35, 'apply_at' => 70],
        ]);
    }
}
