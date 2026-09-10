<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Data\TriageInput;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Enums\TriageRecommendation;
use App\Domain\Triage\Exceptions\TriageException;
use App\Domain\Triage\OpportunityScorer;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\OpportunityReview;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EvaluateOpportunityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reuses_identical_evaluations_and_preserves_prior_snapshots(): void
    {
        $opportunity = $this->opportunity();
        $profile = $this->profile();
        $action = app(EvaluateOpportunity::class);

        $first = $action->execute($opportunity->workspace_id, $opportunity->id, $profile);
        $firstAttributes = $first->fresh()->getRawOriginal();
        $second = $action->execute($opportunity->workspace_id, $opportunity->id, $profile);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($firstAttributes, $second->getRawOriginal());
        $this->assertSame(1, OpportunityEvaluation::query()->count());
        $this->assertSame(TriageRecommendation::Apply, $first->recommendation);
        $this->assertSame(100, $first->score);
        $this->assertSame(['django', 'quality assurance'], $first->input_snapshot['skills']);
        $this->assertSame($profile->definition, $first->profile_snapshot);
    }

    #[Test]
    public function it_creates_history_when_normalized_input_or_profile_changes(): void
    {
        $opportunity = $this->opportunity();
        $action = app(EvaluateOpportunity::class);

        $first = $action->execute($opportunity->workspace_id, $opportunity->id, $this->profile());
        $opportunity->update(['hourly_max' => '18.00']);
        $second = $action->execute($opportunity->workspace_id, $opportunity->id, $this->profile());
        $third = $action->execute($opportunity->workspace_id, $opportunity->id, $this->profile('25.00'));

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($second->id, $third->id);
        $this->assertSame(3, OpportunityEvaluation::query()->count());
        $this->assertSame('60.00', $first->input_snapshot['hourly_max']);
        $this->assertSame('18.00', $second->input_snapshot['hourly_max']);
        $this->assertNotSame($second->profile_version, $third->profile_version);
    }

    #[Test]
    public function it_reproduces_an_old_result_from_its_saved_snapshots(): void
    {
        $opportunity = $this->opportunity();
        $evaluation = app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $this->profile(),
        );
        $opportunity->update([
            'hourly_max' => '10.00',
            'payment_verified' => false,
            'client_rating' => '1.00',
        ]);

        $replayed = (new OpportunityScorer)->evaluate(
            TriageInput::fromArray($evaluation->input_snapshot),
            ScoringProfile::fromArray($evaluation->profile_snapshot),
        );

        $this->assertSame($evaluation->result, $replayed->toArray());
    }

    #[Test]
    public function it_rejects_foreign_workspace_ids_without_disclosure_or_writes(): void
    {
        $opportunity = $this->opportunity();
        $foreignWorkspace = Workspace::factory()->create();

        try {
            app(EvaluateOpportunity::class)->execute($foreignWorkspace->id, $opportunity->id, $this->profile());
            $this->fail('Expected a triage not-found exception.');
        } catch (TriageException $exception) {
            $this->assertSame(TriageErrorCode::NotFound, $exception->errorCode);
            $this->assertSame(TriageErrorCode::NotFound->value, $exception->getMessage());
        }

        $this->assertSame(0, OpportunityEvaluation::query()->count());
    }

    #[Test]
    public function it_exposes_workspace_owned_evaluation_and_review_relations(): void
    {
        $opportunity = $this->opportunity();
        $evaluation = app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $this->profile(),
        );
        $review = OpportunityReview::query()->create([
            'workspace_id' => $opportunity->workspace_id,
            'evaluation_id' => $evaluation->id,
            'human_label' => TriageRecommendation::Apply,
            'reason_code' => null,
            'sample_kind' => 'demo',
            'reviewed_at' => now(),
        ]);
        $relatedEvaluation = $opportunity->evaluations()->sole();
        $workspaceEvaluation = $opportunity->workspace->opportunityEvaluations()->sole();
        $workspaceReview = $opportunity->workspace->opportunityReviews()->sole();
        $relatedReview = $evaluation->review()->sole();

        $this->assertInstanceOf(OpportunityEvaluation::class, $relatedEvaluation);
        $this->assertInstanceOf(OpportunityEvaluation::class, $workspaceEvaluation);
        $this->assertInstanceOf(OpportunityReview::class, $workspaceReview);
        $this->assertInstanceOf(OpportunityReview::class, $relatedReview);

        $this->assertSame($evaluation->id, $relatedEvaluation->id);
        $this->assertSame($evaluation->id, $workspaceEvaluation->id);
        $this->assertSame($review->id, $workspaceReview->id);
        $this->assertSame($review->id, $relatedReview->id);
        $this->assertSame($evaluation->id, $review->evaluation->id);
    }

    #[Test]
    public function it_keeps_evaluation_rows_immutable_and_summary_fields_consistent(): void
    {
        $opportunity = $this->opportunity();
        $evaluation = app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $this->profile(),
        );

        try {
            $evaluation->update(['score' => 0]);
            $this->fail('Expected evaluation mutation to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Opportunity evaluations are immutable.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        OpportunityEvaluation::query()->create([
            'workspace_id' => $opportunity->workspace_id,
            'opportunity_id' => $opportunity->id,
            'engine_version' => 'triage-v1',
            'profile_version' => str_repeat('a', 64),
            'input_sha256' => str_repeat('b', 64),
            'profile_snapshot' => [],
            'input_snapshot' => [],
            'result' => ['recommendation' => 'APPLY', 'score' => 100],
            'recommendation' => TriageRecommendation::Skip,
            'score' => 0,
        ]);
    }

    private function opportunity(): Opportunity
    {
        $opportunity = Opportunity::factory()->create();
        $opportunity->skills()->createMany([
            ['name' => 'Quality Assurance', 'position' => 0],
            ['name' => 'Django', 'position' => 1],
        ]);

        return $opportunity;
    }

    private function profile(string $minimumHourlyUsd = '20.00'): ScoringProfile
    {
        return ScoringProfile::fromArray([
            'schema_version' => 1,
            'label' => 'Synthetic demo profile',
            'purpose' => 'demo',
            'minimum_hourly_usd' => $minimumHourlyUsd,
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
