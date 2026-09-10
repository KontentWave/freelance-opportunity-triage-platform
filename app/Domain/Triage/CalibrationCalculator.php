<?php

namespace App\Domain\Triage;

use App\Domain\Triage\Enums\TriageRecommendation;

final class CalibrationCalculator
{
    /**
     * @param  list<array{machine_label: TriageRecommendation, human_label: ?TriageRecommendation, sample_kind: ?string, missing_fields: list<string>, reason_code: ?string}>  $samples
     * @return array<string, mixed>
     */
    public function calculate(
        array $samples,
        string $profilePurpose,
        string $engineVersion,
        string $profileVersion,
    ): array {
        $labels = array_map(
            static fn (TriageRecommendation $recommendation): string => $recommendation->value,
            TriageRecommendation::cases(),
        );
        $matrix = [];

        foreach ($labels as $machineLabel) {
            $matrix[$machineLabel] = array_fill_keys($labels, 0);
        }

        $sampleKindCounts = ['demo' => 0, 'real' => 0];
        $missingFieldCounts = [];
        $disagreementReasonCounts = [];
        $reviewedCount = 0;
        $truePositive = 0;
        $falsePositive = 0;
        $trueNegative = 0;
        $falseNegative = 0;

        foreach ($samples as $sample) {
            foreach ($sample['missing_fields'] as $field) {
                $missingFieldCounts[$field] = ($missingFieldCounts[$field] ?? 0) + 1;
            }

            if ($sample['human_label'] === null || $sample['sample_kind'] === null) {
                continue;
            }

            $reviewedCount++;
            $machineLabel = $sample['machine_label']->value;
            $humanLabel = $sample['human_label']->value;
            $matrix[$machineLabel][$humanLabel]++;
            $sampleKindCounts[$sample['sample_kind']]++;

            $machineKeeps = $sample['machine_label'] !== TriageRecommendation::Skip;
            $humanKeeps = $sample['human_label'] !== TriageRecommendation::Skip;

            if ($machineKeeps && $humanKeeps) {
                $truePositive++;
            } elseif ($machineKeeps) {
                $falsePositive++;
            } elseif ($humanKeeps) {
                $falseNegative++;
            } else {
                $trueNegative++;
            }

            if ($sample['machine_label'] !== $sample['human_label'] && $sample['reason_code'] !== null) {
                $disagreementReasonCounts[$sample['reason_code']] = ($disagreementReasonCounts[$sample['reason_code']] ?? 0) + 1;
            }
        }

        ksort($missingFieldCounts, SORT_STRING);
        ksort($disagreementReasonCounts, SORT_STRING);

        $selectedCount = count($samples);
        $incompleteCount = $selectedCount - $reviewedCount;
        $isComplete = $incompleteCount === 0;
        $skipRate = $isComplete
            ? $this->percentage($trueNegative + $falseNegative, $selectedCount)
            : null;
        $falseNegativeRate = $isComplete
            ? $this->percentage($falseNegative, $truePositive + $falseNegative)
            : null;
        $falsePositiveRate = $isComplete
            ? $this->percentage($falsePositive, $trueNegative + $falsePositive)
            : null;
        $hasDemoEvidence = $profilePurpose === 'demo' || $sampleKindCounts['demo'] > 0;
        $hasBothHumanClasses = ($truePositive + $falseNegative) > 0
            && ($trueNegative + $falsePositive) > 0;

        if ($hasDemoEvidence) {
            $dataStatus = 'DEMO_ONLY';
        } elseif ($selectedCount < 30 || ! $isComplete || ! $hasBothHumanClasses) {
            $dataStatus = 'INSUFFICIENT_DATA';
        } else {
            $dataStatus = 'READY';
        }

        $targetsMet = $dataStatus === 'READY'
            ? 2 * ($trueNegative + $falseNegative) >= $selectedCount
                && 20 * $falseNegative <= $truePositive + $falseNegative
            : null;

        return [
            'n_selected' => $selectedCount,
            'n_reviewed' => $reviewedCount,
            'n_incomplete' => $incompleteCount,
            'engine_version' => $engineVersion,
            'profile_version' => $profileVersion,
            'sample_kind_counts' => $sampleKindCounts,
            'confusion_matrix' => $matrix,
            'true_positive' => $truePositive,
            'false_positive' => $falsePositive,
            'true_negative' => $trueNegative,
            'false_negative' => $falseNegative,
            'skip_rate_percent' => $skipRate,
            'false_negative_rate_percent' => $falseNegativeRate,
            'false_positive_rate_percent' => $falsePositiveRate,
            'missing_field_counts' => $missingFieldCounts,
            'disagreement_reason_counts' => $disagreementReasonCounts,
            'data_status' => $dataStatus,
            'targets_met' => $targetsMet,
        ];
    }

    private function percentage(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return round(100 * $numerator / $denominator, 2);
    }
}
