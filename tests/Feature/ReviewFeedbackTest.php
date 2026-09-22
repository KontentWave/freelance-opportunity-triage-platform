<?php

namespace Tests\Feature;

use App\Application\Triage\BuildCalibrationReport;
use App\Application\Triage\EvaluateOpportunity;
use App\Domain\Triage\Data\ScoringProfile;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use App\Models\OpportunityEnrichment;
use App\Models\OpportunityEvaluation;
use App\Models\OpportunityReview;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewFeedbackTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_saves_feedback_without_changing_machine_evidence(): void
    {
        Http::preventStrayRequests();
        [$user, $opportunity, $evaluation, $enrichment] = $this->reviewContext();
        $emailResult = $evaluation->result;
        $manualResult = $enrichment->result;

        $response = $this->actingAs($user)->putJson(
            '/review/v1/opportunities/'.$opportunity->id.'/review',
            [
                'evaluation_id' => $evaluation->id,
                'enrichment_id' => $enrichment->id,
                'human_label' => 'APPLY',
                'reason_code' => 'economics',
                'notes' => '<script>private note</script>',
                'outcome' => 'applied',
                'sample_kind' => 'demo',
            ],
        );

        $reviewId = $response
            ->assertOk()
            ->assertJsonPath('data.current_review.enrichment_id', $enrichment->id)
            ->assertJsonPath('data.current_review.human_label', 'APPLY')
            ->assertJsonPath('data.current_review.reason_code', 'economics')
            ->assertJsonPath('data.current_review.notes', '<script>private note</script>')
            ->assertJsonPath('data.current_review.outcome', 'applied')
            ->assertJsonPath('data.current_review.sample_kind', 'demo')
            ->json('data.current_review.id');

        $this->assertDatabaseHas('opportunity_reviews', [
            'id' => $reviewId,
            'workspace_id' => $opportunity->workspace_id,
            'evaluation_id' => $evaluation->id,
            'enrichment_id' => $enrichment->id,
            'human_label' => 'APPLY',
            'reason_code' => 'economics',
            'notes' => '<script>private note</script>',
            'outcome' => 'applied',
            'sample_kind' => 'demo',
        ]);
        $this->assertSame($emailResult, $evaluation->fresh()->result);
        $this->assertSame($manualResult, $enrichment->fresh()->result);
        $this->assertDatabaseCount('opportunity_evaluations', 1);
        $this->assertDatabaseCount('opportunity_enrichments', 1);
    }

    #[Test]
    public function it_saves_feedback_for_the_displayed_fallback_evaluation_without_selecting_a_pointer(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);
        $profile = app(LocalScoringProfileLoader::class)->load(config('opportunity_review.profile_path'));
        $historicalProfile = ScoringProfile::fromArray([
            ...$profile->definition,
            'label' => 'Historical test profile',
        ]);
        $historicalEvaluation = app(EvaluateOpportunity::class)->execute(
            $workspace->id,
            $opportunity->id,
            $historicalProfile,
        );
        $evaluation = app(EvaluateOpportunity::class)->execute($workspace->id, $opportunity->id, $profile);

        $this->assertNull($opportunity->fresh()->review_evaluation_id);
        $this->actingAs($user)
            ->getJson('/review/v1/opportunities/'.$opportunity->id)
            ->assertOk()
            ->assertJsonPath('data.evaluation_id', $evaluation->id);

        $this->actingAs($user)->putJson(
            '/review/v1/opportunities/'.$opportunity->id.'/review',
            [
                'evaluation_id' => $evaluation->id,
                'enrichment_id' => null,
                'human_label' => $evaluation->recommendation->value,
                'reason_code' => null,
                'notes' => null,
                'outcome' => null,
                'sample_kind' => 'demo',
            ],
        )->assertOk()
            ->assertJsonPath('data.evaluation_id', $evaluation->id);

        $this->actingAs($user)->putJson(
            '/review/v1/opportunities/'.$opportunity->id.'/review',
            [
                'evaluation_id' => $historicalEvaluation->id,
                'enrichment_id' => null,
                'human_label' => $historicalEvaluation->recommendation->value,
                'reason_code' => null,
                'notes' => null,
                'outcome' => null,
                'sample_kind' => 'demo',
            ],
        )->assertStatus(409)->assertJsonMissing(['exception']);

        $this->assertDatabaseHas('opportunity_reviews', [
            'evaluation_id' => $evaluation->id,
            'sample_kind' => 'demo',
        ]);
        $this->assertNull($opportunity->fresh()->review_evaluation_id);
    }

    #[Test]
    public function it_keeps_calibration_on_email_predictions_and_human_labels(): void
    {
        [$user, $opportunity, $evaluation, $enrichment] = $this->reviewContext();
        $this->assertSame('MAYBE', $evaluation->recommendation->value);
        $this->assertSame('APPLY', $enrichment->result['recommendation']);

        $this->actingAs($user)->putJson(
            '/review/v1/opportunities/'.$opportunity->id.'/review',
            [
                'evaluation_id' => $evaluation->id,
                'enrichment_id' => $enrichment->id,
                'human_label' => 'APPLY',
                'reason_code' => 'economics',
                'notes' => null,
                'outcome' => null,
                'sample_kind' => 'demo',
            ],
        )->assertOk();

        $report = app(BuildCalibrationReport::class)->execute($opportunity->workspace_id, [$evaluation->id]);

        $this->assertSame(1, $report['confusion_matrix']['MAYBE']['APPLY']);
        $this->assertSame(0, $report['confusion_matrix']['APPLY']['APPLY']);
        $this->assertSame(1, $report['true_positive']);
        $this->assertSame(['economics' => 1], $report['disagreement_reason_counts']);
        $this->assertSame('DEMO_ONLY', $report['data_status']);
    }

    #[Test]
    public function it_rejects_stale_contexts_and_updates_only_the_current_review(): void
    {
        $this->freezeTime();
        [$user, $opportunity, $evaluation, $enrichment] = $this->reviewContext();
        $feedbackUrl = '/review/v1/opportunities/'.$opportunity->id.'/review';
        $payload = [
            'evaluation_id' => $evaluation->id,
            'enrichment_id' => $enrichment->id,
            'human_label' => 'APPLY',
            'reason_code' => 'economics',
            'notes' => 'Initial feedback.',
            'outcome' => 'applied',
            'sample_kind' => 'demo',
        ];

        $reviewId = $this->actingAs($user)->putJson($feedbackUrl, $payload)
            ->assertOk()
            ->json('data.current_review.id');
        $original = OpportunityReview::query()->findOrFail($reviewId)->getRawOriginal();

        $this->travel(1)->minute();
        $this->actingAs($user)->putJson($feedbackUrl, $payload)->assertOk();
        $this->assertSame($original, OpportunityReview::query()->findOrFail($reviewId)->getRawOriginal());

        $opportunity->update(['hourly_max' => '35.00']);
        $this->actingAs($user)->putJson($feedbackUrl, [...$payload, 'notes' => 'Must not persist.'])
            ->assertStatus(409)
            ->assertJsonMissing(['exception']);
        $this->assertSame($original, OpportunityReview::query()->findOrFail($reviewId)->getRawOriginal());

        $opportunity->update(['hourly_max' => null]);
        $nextEnrichmentId = $this->actingAs($user)->postJson(
            '/review/v1/opportunities/'.$opportunity->id.'/enrichments',
            [
                'evaluation_id' => $evaluation->id,
                'expected_enrichment_id' => $enrichment->id,
                'full_description' => 'A newer confirmed context.',
                'overrides' => ['hourly_max' => '50.00'],
            ],
        )->assertOk()->json('data.current_enrichment.id');

        $this->actingAs($user)->putJson($feedbackUrl, [...$payload, 'notes' => 'Still stale.'])
            ->assertStatus(409)
            ->assertJsonMissing(['exception']);
        $this->assertSame($original, OpportunityReview::query()->findOrFail($reviewId)->getRawOriginal());

        $this->travel(1)->minute();
        $this->actingAs($user)->putJson($feedbackUrl, [
            ...$payload,
            'enrichment_id' => $nextEnrichmentId,
            'notes' => 'Updated current feedback.',
            'outcome' => 'in_discussion',
        ])->assertOk()->assertJsonPath('data.current_review.id', $reviewId);

        $this->assertDatabaseCount('opportunity_reviews', 1);
        $this->assertDatabaseHas('opportunity_reviews', [
            'id' => $reviewId,
            'enrichment_id' => $nextEnrichmentId,
            'notes' => 'Updated current feedback.',
            'outcome' => 'in_discussion',
        ]);
    }

    /** @return array{User, Opportunity, OpportunityEvaluation, OpportunityEnrichment} */
    private function reviewContext(): array
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $opportunity = Opportunity::factory()->create([
            'workspace_id' => $workspace->id,
            'hourly_max' => null,
            'client_rating' => '4.90',
            'payment_verified' => true,
        ]);
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);
        $profile = app(LocalScoringProfileLoader::class)->load(config('opportunity_review.profile_path'));
        $evaluation = app(EvaluateOpportunity::class)->execute($workspace->id, $opportunity->id, $profile);
        $opportunity->forceFill(['review_evaluation_id' => $evaluation->id])->save();

        $enrichmentId = $this->actingAs($user)->postJson(
            '/review/v1/opportunities/'.$opportunity->id.'/enrichments',
            [
                'evaluation_id' => $evaluation->id,
                'expected_enrichment_id' => null,
                'full_description' => 'Confirmed complete scope.',
                'overrides' => ['hourly_max' => '40.00'],
            ],
        )->assertOk()->json('data.current_enrichment.id');

        return [$user, $opportunity, $evaluation, OpportunityEnrichment::query()->findOrFail($enrichmentId)];
    }
}
