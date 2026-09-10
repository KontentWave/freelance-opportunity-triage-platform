<?php

namespace Tests\Unit\Domain\Triage;

use App\Domain\Triage\CalibrationCalculator;
use App\Domain\Triage\Enums\TriageRecommendation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CalibrationCalculatorTest extends TestCase
{
    #[Test]
    public function it_calculates_calibration_counts_and_unrounded_targets(): void
    {
        $samples = [
            ...array_fill(0, 10, $this->sample(TriageRecommendation::Apply, TriageRecommendation::Apply, 'real')),
            ...array_fill(0, 5, $this->sample(TriageRecommendation::Maybe, TriageRecommendation::Apply, 'real')),
            ...array_fill(0, 15, $this->sample(TriageRecommendation::Skip, TriageRecommendation::Skip, 'real')),
        ];

        $report = (new CalibrationCalculator)->calculate($samples, 'personal', 'triage-v1', 'profile-hash');

        $this->assertSame(30, $report['n_selected']);
        $this->assertSame(30, $report['n_reviewed']);
        $this->assertSame(10, $report['confusion_matrix']['APPLY']['APPLY']);
        $this->assertSame(5, $report['confusion_matrix']['MAYBE']['APPLY']);
        $this->assertSame(15, $report['confusion_matrix']['SKIP']['SKIP']);
        $this->assertSame(15, $report['true_positive']);
        $this->assertSame(0, $report['false_positive']);
        $this->assertSame(15, $report['true_negative']);
        $this->assertSame(0, $report['false_negative']);
        $this->assertSame(50.0, $report['skip_rate_percent']);
        $this->assertSame(0.0, $report['false_negative_rate_percent']);
        $this->assertSame(0.0, $report['false_positive_rate_percent']);
        $this->assertSame('READY', $report['data_status']);
        $this->assertTrue($report['targets_met']);
    }

    #[Test]
    public function it_fails_the_ready_target_below_fifty_percent_even_with_zero_false_negatives(): void
    {
        $samples = [
            ...array_fill(0, 16, $this->sample(TriageRecommendation::Apply, TriageRecommendation::Apply, 'real')),
            ...array_fill(0, 14, $this->sample(TriageRecommendation::Skip, TriageRecommendation::Skip, 'real')),
        ];

        $report = (new CalibrationCalculator)->calculate($samples, 'personal', 'triage-v1', 'profile-hash');

        $this->assertSame(46.67, $report['skip_rate_percent']);
        $this->assertSame(0.0, $report['false_negative_rate_percent']);
        $this->assertSame('READY', $report['data_status']);
        $this->assertFalse($report['targets_met']);
    }

    #[Test]
    public function it_never_passes_calibration_without_sufficient_real_labels(): void
    {
        $demoReport = (new CalibrationCalculator)->calculate(
            [$this->sample(TriageRecommendation::Skip, TriageRecommendation::Skip, 'demo')],
            'personal',
            'triage-v1',
            'profile-hash',
        );
        $incompleteReport = (new CalibrationCalculator)->calculate(
            [
                $this->sample(TriageRecommendation::Apply, TriageRecommendation::Apply, 'real'),
                $this->sample(TriageRecommendation::Skip, null, null),
            ],
            'personal',
            'triage-v1',
            'profile-hash',
        );

        $this->assertSame('DEMO_ONLY', $demoReport['data_status']);
        $this->assertNull($demoReport['targets_met']);
        $this->assertSame('INSUFFICIENT_DATA', $incompleteReport['data_status']);
        $this->assertSame(1, $incompleteReport['n_incomplete']);
        $this->assertNull($incompleteReport['skip_rate_percent']);
        $this->assertNull($incompleteReport['false_negative_rate_percent']);
        $this->assertNull($incompleteReport['false_positive_rate_percent']);
        $this->assertNull($incompleteReport['targets_met']);
    }

    #[Test]
    public function it_reports_null_denominators_and_aggregate_reason_counts_for_a_small_real_cohort(): void
    {
        $samples = [
            $this->sample(TriageRecommendation::Apply, TriageRecommendation::Skip, 'real', ['currency'], 'economics'),
            $this->sample(TriageRecommendation::Maybe, TriageRecommendation::Skip, 'real', ['currency', 'skills'], 'fit'),
        ];

        $report = (new CalibrationCalculator)->calculate($samples, 'personal', 'triage-v1', 'profile-hash');

        $this->assertSame('INSUFFICIENT_DATA', $report['data_status']);
        $this->assertSame(0.0, $report['skip_rate_percent']);
        $this->assertNull($report['false_negative_rate_percent']);
        $this->assertSame(100.0, $report['false_positive_rate_percent']);
        $this->assertSame(['currency' => 2, 'skills' => 1], $report['missing_field_counts']);
        $this->assertSame(['economics' => 1, 'fit' => 1], $report['disagreement_reason_counts']);
        $this->assertNull($report['targets_met']);
    }

    /**
     * @param  list<string>  $missingFields
     * @return array{machine_label: TriageRecommendation, human_label: ?TriageRecommendation, sample_kind: ?string, missing_fields: list<string>, reason_code: ?string}
     */
    private function sample(
        TriageRecommendation $machineLabel,
        ?TriageRecommendation $humanLabel,
        ?string $sampleKind,
        array $missingFields = [],
        ?string $reasonCode = null,
    ): array {
        return [
            'machine_label' => $machineLabel,
            'human_label' => $humanLabel,
            'sample_kind' => $sampleKind,
            'missing_fields' => $missingFields,
            'reason_code' => $reasonCode,
        ];
    }
}
