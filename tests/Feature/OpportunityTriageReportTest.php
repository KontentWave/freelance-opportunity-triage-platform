<?php

namespace Tests\Feature;

use App\Application\Triage\BuildCalibrationReport;
use App\Application\Triage\EvaluateOpportunity;
use App\Application\Triage\RecordOpportunityReview;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpportunityTriageReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_incomplete_reviews_without_calibration_rates(): void
    {
        $first = $this->evaluation();
        $second = $this->evaluation($first->workspace_id);
        app(RecordOpportunityReview::class)->execute($first->workspace_id, $first->id, 'APPLY', null, 'real');

        $report = app(BuildCalibrationReport::class)->execute($first->workspace_id, [$first->id, $second->id]);

        $this->assertSame(2, $report['n_selected']);
        $this->assertSame(1, $report['n_reviewed']);
        $this->assertSame(1, $report['n_incomplete']);
        $this->assertSame('INSUFFICIENT_DATA', $report['data_status']);
        $this->assertNull($report['skip_rate_percent']);
        $this->assertNull($report['false_negative_rate_percent']);
        $this->assertNull($report['false_positive_rate_percent']);
        $this->assertNull($report['targets_met']);
    }

    #[Test]
    public function it_rejects_mixed_versions_and_duplicate_opportunities_in_a_cohort(): void
    {
        $first = $this->evaluation();
        $mixedProfile = $this->evaluation($first->workspace_id, $this->profile('25.00'));
        $opportunity = $first->opportunity;
        $opportunity->update(['hourly_max' => '55.00']);
        $sameOpportunity = app(EvaluateOpportunity::class)->execute(
            $first->workspace_id,
            $opportunity->id,
            $this->profile(),
        );
        $invalidCohorts = [
            [$first->id, $mixedProfile->id],
            [$first->id, $sameOpportunity->id],
            [$first->id, $first->id],
        ];

        foreach ($invalidCohorts as $cohort) {
            try {
                app(BuildCalibrationReport::class)->execute($first->workspace_id, $cohort);
                $this->fail('Expected an invalid cohort exception.');
            } catch (TriageException $exception) {
                $this->assertSame(TriageErrorCode::CohortInvalid, $exception->errorCode);
            }
        }
    }

    #[Test]
    public function it_isolates_calibration_reports_by_workspace(): void
    {
        $evaluation = $this->evaluation();
        $foreignWorkspace = Workspace::factory()->create();

        try {
            app(BuildCalibrationReport::class)->execute($foreignWorkspace->id, [$evaluation->id]);
            $this->fail('Expected a triage not-found exception.');
        } catch (TriageException $exception) {
            $this->assertSame(TriageErrorCode::NotFound, $exception->errorCode);
        }
    }

    private function evaluation(?string $workspaceId = null, ?ScoringProfile $profile = null): OpportunityEvaluation
    {
        $opportunity = Opportunity::factory()->create($workspaceId === null ? [] : ['workspace_id' => $workspaceId]);
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);

        return app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $profile ?? $this->profile(),
        );
    }

    private function profile(string $minimumHourlyUsd = '20.00'): ScoringProfile
    {
        return ScoringProfile::fromArray([
            'schema_version' => 1,
            'label' => 'Personal test profile',
            'purpose' => 'personal',
            'minimum_hourly_usd' => $minimumHourlyUsd,
            'preferred_skills' => ['django'],
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
