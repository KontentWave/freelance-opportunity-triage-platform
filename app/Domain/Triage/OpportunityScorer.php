<?php

namespace App\Domain\Triage;

use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Data\TriageInput;
use App\Domain\Triage\Data\TriageResult;
use App\Domain\Triage\Enums\TriageRecommendation;

final class OpportunityScorer
{
    public const ENGINE_VERSION = 'triage-v1';

    public function evaluate(TriageInput $input, ScoringProfile $profile): TriageResult
    {
        $missingFields = [];
        $hardExclusions = [];
        $contributions = [
            $this->skillContribution($input, $profile, $missingFields),
            $this->rateContribution($input, $profile, $missingFields, $hardExclusions),
            $this->paymentContribution($input, $profile, $missingFields),
            $this->ratingContribution($input, $profile, $missingFields),
        ];
        $score = array_sum(array_column($contributions, 'points'));
        $missingFields = array_values(array_unique($missingFields, SORT_STRING));
        sort($missingFields, SORT_STRING);

        if ($hardExclusions !== []) {
            $recommendation = TriageRecommendation::Skip;
            $decisionReasonCode = 'decision.below_rate_floor';
            $decisionExplanation = 'The confirmed hourly rate ceiling is below the configured minimum.';
        } elseif (in_array('unknown', array_column($contributions, 'state'), true)) {
            $recommendation = TriageRecommendation::Maybe;
            $decisionReasonCode = 'decision.incomplete_data';
            $decisionExplanation = 'One or more signals are unknown, so this opportunity requires manual review.';
        } elseif ($score >= $profile->thresholds['apply_at']) {
            $recommendation = TriageRecommendation::Apply;
            $decisionReasonCode = 'decision.score_apply';
            $decisionExplanation = 'The complete known signals meet the configured apply threshold.';
        } elseif ($score < $profile->thresholds['skip_below']) {
            $recommendation = TriageRecommendation::Skip;
            $decisionReasonCode = 'decision.score_skip';
            $decisionExplanation = 'The complete known signals fall below the configured skip threshold.';
        } else {
            $recommendation = TriageRecommendation::Maybe;
            $decisionReasonCode = 'decision.score_maybe';
            $decisionExplanation = 'The complete known signals fall between the configured thresholds.';
        }

        return new TriageResult(
            recommendation: $recommendation,
            score: $score,
            contributions: $contributions,
            missingFields: $missingFields,
            hardExclusions: $hardExclusions,
            decisionReasonCode: $decisionReasonCode,
            decisionExplanation: $decisionExplanation,
        );
    }

    /** @param list<string> $missingFields */
    private function skillContribution(TriageInput $input, ScoringProfile $profile, array &$missingFields): array
    {
        $weight = $profile->weights['skill_match'];
        $matches = array_intersect($input->skills, $profile->preferredSkills) !== [];

        if ($matches) {
            return $this->contribution('skill_match', 'matched', $weight, $weight, 'skills.match', 'At least one visible skill matches a preferred skill.');
        }

        if ($input->skills === [] || $input->hiddenSkillCount > 0) {
            $missingFields[] = 'skills';

            return $this->contribution('skill_match', 'unknown', 0, $weight, 'skills.unknown', 'Visible skills do not provide enough evidence for an exact match decision.');
        }

        return $this->contribution('skill_match', 'not_matched', 0, $weight, 'skills.no_match', 'No visible skill exactly matches a preferred skill.');
    }

    /**
     * @param  list<string>  $missingFields
     * @param  list<string>  $hardExclusions
     */
    private function rateContribution(TriageInput $input, ScoringProfile $profile, array &$missingFields, array &$hardExclusions): array
    {
        $weight = $profile->weights['rate'];
        $maximumCents = $this->decimalCents($input->hourlyMax);
        $rateUnknown = false;

        if ($input->contractType !== 'hourly') {
            $missingFields[] = 'contract_type';
            $rateUnknown = true;
        }

        if ($input->currency !== 'USD') {
            $missingFields[] = 'currency';
            $rateUnknown = true;
        }

        if ($maximumCents === null || $maximumCents <= 0) {
            $missingFields[] = 'hourly_max';
            $rateUnknown = true;
        }

        if ($rateUnknown) {
            return $this->contribution('rate', 'unknown', 0, $weight, 'rate.unknown', 'The hourly USD maximum is not fully known and positive.');
        }

        if ($maximumCents < $this->decimalCents($profile->minimumHourlyUsd)) {
            $hardExclusions[] = 'rate.maximum_below_minimum';

            return $this->contribution('rate', 'not_matched', 0, $weight, 'rate.below_floor', 'The known hourly USD maximum is below the configured minimum.');
        }

        return $this->contribution('rate', 'matched', $weight, $weight, 'rate.meets_floor', 'The known hourly USD maximum meets the configured minimum.');
    }

    /** @param list<string> $missingFields */
    private function paymentContribution(TriageInput $input, ScoringProfile $profile, array &$missingFields): array
    {
        $weight = $profile->weights['payment_verified'];

        if ($input->paymentVerified === true) {
            return $this->contribution('payment_verified', 'matched', $weight, $weight, 'payment.verified', 'The client payment method is verified.');
        }

        if ($input->paymentVerified === false) {
            return $this->contribution('payment_verified', 'not_matched', 0, $weight, 'payment.unverified', 'The client payment method is not verified.');
        }

        $missingFields[] = 'payment_verified';

        return $this->contribution('payment_verified', 'unknown', 0, $weight, 'payment.unknown', 'Payment verification is unknown.');
    }

    /** @param list<string> $missingFields */
    private function ratingContribution(TriageInput $input, ScoringProfile $profile, array &$missingFields): array
    {
        $weight = $profile->weights['client_rating'];
        $ratingCents = $this->decimalCents($input->clientRating);

        if ($ratingCents === null || $ratingCents <= 0) {
            $missingFields[] = 'client_rating';

            return $this->contribution('client_rating', 'unknown', 0, $weight, 'rating.unknown', 'The client is unrated or the rating is unknown.');
        }

        if ($ratingCents < $this->decimalCents($profile->minimumClientRating)) {
            return $this->contribution('client_rating', 'not_matched', 0, $weight, 'rating.below_minimum', 'The known client rating is below the configured minimum.');
        }

        return $this->contribution('client_rating', 'matched', $weight, $weight, 'rating.meets_minimum', 'The known client rating meets the configured minimum.');
    }

    /** @return array{rule: string, state: string, points: int, maximum_points: int, reason_code: string, explanation: string} */
    private function contribution(string $rule, string $state, int $points, int $maximumPoints, string $reasonCode, string $explanation): array
    {
        return compact('rule', 'state', 'points') + [
            'maximum_points' => $maximumPoints,
            'reason_code' => $reasonCode,
            'explanation' => $explanation,
        ];
    }

    private function decimalCents(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        [$whole, $fraction] = explode('.', $value);

        return ((int) $whole * 100) + (int) $fraction;
    }
}
