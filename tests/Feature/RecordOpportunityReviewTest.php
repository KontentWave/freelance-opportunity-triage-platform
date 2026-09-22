<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Application\Triage\RecordOpportunityReview;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Enums\TriageRecommendation;
use App\Domain\Triage\Exceptions\TriageException;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\OpportunityReview;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecordOpportunityReviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_human_feedback_without_changing_the_machine_result(): void
    {
        $this->freezeTime();
        $evaluation = $this->evaluation();
        $evaluationResult = $evaluation->result;
        $action = app(RecordOpportunityReview::class);

        $created = $action->execute($evaluation->workspace_id, $evaluation->id, 'APPLY', null, 'demo');
        $originalAttributes = $created->fresh()->getRawOriginal();
        $this->travel(1)->minute();
        $identical = $action->execute($evaluation->workspace_id, $evaluation->id, 'APPLY', null, 'demo');
        $this->travel(1)->minute();
        $revised = $action->execute($evaluation->workspace_id, $evaluation->id, 'SKIP', 'fit', 'real');

        $this->assertSame($created->id, $identical->id);
        $this->assertSame($originalAttributes, $identical->getRawOriginal());
        $this->assertSame($created->id, $revised->id);
        $this->assertSame(TriageRecommendation::Skip, $revised->human_label);
        $this->assertSame('fit', $revised->reason_code);
        $this->assertSame('real', $revised->sample_kind);
        $this->assertNotSame($originalAttributes['reviewed_at'], $revised->getRawOriginal('reviewed_at'));
        $this->assertSame(1, OpportunityReview::query()->count());
        $this->assertSame($evaluationResult, $evaluation->fresh()->result);
    }

    #[Test]
    public function it_rejects_invalid_labels_reasons_and_sample_kinds_without_writing(): void
    {
        $evaluation = $this->evaluation();
        $action = app(RecordOpportunityReview::class);
        $invalidReviews = [
            ['KEEP', null, 'demo'],
            ['APPLY', 'unsupported', 'demo'],
            ['APPLY', null, 'synthetic'],
            ['SKIP', null, 'real'],
        ];

        foreach ($invalidReviews as [$label, $reason, $sampleKind]) {
            try {
                $action->execute($evaluation->workspace_id, $evaluation->id, $label, $reason, $sampleKind);
                $this->fail('Expected invalid review input to be rejected.');
            } catch (TriageException $exception) {
                $this->assertSame(TriageErrorCode::ReviewInvalid, $exception->errorCode);
            }
        }

        $this->assertSame(0, OpportunityReview::query()->count());
    }

    #[Test]
    public function it_rejects_foreign_workspace_ids_without_disclosure_or_writes(): void
    {
        $evaluation = $this->evaluation();
        $foreignWorkspace = Workspace::factory()->create();

        foreach (['APPLY', 'INVALID'] as $label) {
            try {
                app(RecordOpportunityReview::class)->execute(
                    $foreignWorkspace->id,
                    $evaluation->id,
                    $label,
                    null,
                    'demo',
                );
                $this->fail('Expected a triage not-found exception.');
            } catch (TriageException $exception) {
                $this->assertSame(TriageErrorCode::NotFound, $exception->errorCode);
            }
        }

        $this->assertSame(0, OpportunityReview::query()->count());
    }

    private function evaluation(): OpportunityEvaluation
    {
        $opportunity = Opportunity::factory()->create();
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);

        return app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $this->profile(),
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
