<?php

namespace Tests\Feature;

use App\Application\Triage\BuildOpportunityTriageInput;
use App\Domain\Triage\Enums\TriageRecommendation;
use App\Domain\Triage\OpportunityScorer;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\OpportunityReview;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_ranks_filters_and_paginates_saved_suggestions(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $foreignWorkspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);

        $unscored = $this->opportunity($workspace, 'U', '2026-09-10');
        $apply100 = $this->opportunity($workspace, 'A', '2026-09-10');
        $apply70 = $this->opportunity($workspace, 'B', '2026-09-11');
        $maybe70 = $this->opportunity($workspace, 'C', '2026-09-11');
        $maybe60 = $this->opportunity($workspace, 'D', '2026-09-10');
        $skip30 = $this->opportunity($workspace, 'E', '2026-09-11');

        $this->evaluation($apply100, TriageRecommendation::Apply, 100);
        $this->evaluation($apply70, TriageRecommendation::Apply, 70);
        $this->evaluation($maybe70, TriageRecommendation::Maybe, 70, ['hourly_max']);
        $reviewedEvaluation = $this->evaluation($maybe60, TriageRecommendation::Maybe, 60);
        $this->evaluation($skip30, TriageRecommendation::Skip, 30);
        OpportunityReview::query()->create([
            'workspace_id' => $workspace->id,
            'evaluation_id' => $reviewedEvaluation->id,
            'human_label' => TriageRecommendation::Maybe,
            'reason_code' => null,
            'sample_kind' => 'demo',
            'reviewed_at' => now(),
        ]);
        $this->opportunity($foreignWorkspace, 'Foreign', '2026-09-12');

        $response = $this->actingAs($user)->getJson('/review/v1/opportunities')->assertOk();
        $this->assertSame(['U', 'A', 'B', 'C', 'D', 'E'], collect($response->json('data'))->pluck('title')->all());
        $response->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.total', 6);

        $this->actingAs($user)
            ->getJson('/review/v1/opportunities?recommendation=MAYBE&missing=present')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'C');

        $this->actingAs($user)
            ->getJson('/review/v1/opportunities?review=reviewed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'D');

        foreach (range(1, 20) as $index) {
            $this->opportunity($workspace, 'Extra '.$index, '2026-09-01');
        }

        $pageOne = $this->actingAs($user)->getJson('/review/v1/opportunities?page=1')->assertOk();
        $pageTwo = $this->actingAs($user)->getJson('/review/v1/opportunities?page=2')->assertOk();
        $this->assertCount(25, $pageOne->json('data'));
        $this->assertCount(1, $pageTwo->json('data'));
        $this->assertEmpty(array_intersect(
            collect($pageOne->json('data'))->pluck('id')->all(),
            collect($pageTwo->json('data'))->pluck('id')->all(),
        ));

        $this->assertSame(5, OpportunityEvaluation::query()->count());
        $this->assertSame(1, OpportunityReview::query()->count());
        $this->assertNull($unscored->fresh()->review_evaluation_id);
    }

    #[Test]
    public function it_exposes_reasons_unknowns_and_manual_review_context(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $opportunity = Opportunity::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => '<script>alert("private")</script>',
            'canonical_url' => 'https://www.upwork.com/jobs/~1234567890',
            'hourly_max' => null,
            'client_rating' => '4.90',
            'payment_verified' => true,
        ]);
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);
        $evaluation = $this->evaluation($opportunity, TriageRecommendation::Maybe, 70, ['hourly_max']);

        $response = $this->actingAs($user)
            ->getJson('/review/v1/opportunities/'.$opportunity->id)
            ->assertOk()
            ->assertJsonPath('data.title', '<script>alert("private")</script>')
            ->assertJsonPath('data.basis', 'Email evidence')
            ->assertJsonPath('data.recommendation', 'MAYBE')
            ->assertJsonPath('data.score', 70)
            ->assertJsonPath('data.missing_fields.0', 'hourly_max')
            ->assertJsonPath('data.canonical_url', 'https://www.upwork.com/jobs/~1234567890');

        $this->assertCount(4, $response->json('data.email_result.contributions'));
        $this->assertSame($evaluation->id, $response->json('data.evaluation_id'));
        $this->assertSame(1, OpportunityEvaluation::query()->count());
    }

    private function opportunity(Workspace $workspace, string $title, string $postedOn): Opportunity
    {
        return Opportunity::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'posted_on' => $postedOn,
        ]);
    }

    /** @param list<string> $missingFields */
    private function evaluation(
        Opportunity $opportunity,
        TriageRecommendation $recommendation,
        int $score,
        array $missingFields = [],
    ): OpportunityEvaluation {
        $profile = app(LocalScoringProfileLoader::class)->load(config('opportunity_review.profile_path'));
        $input = app(BuildOpportunityTriageInput::class)->execute($opportunity);
        $rulePoints = intdiv($score, 4);
        $contributions = collect(['skill_match', 'rate', 'payment_verified', 'client_rating'])
            ->map(fn (string $rule): array => [
                'rule' => $rule,
                'state' => 'matched',
                'points' => $rulePoints,
                'maximum_points' => 25,
                'reason_code' => $rule.'.matched',
                'explanation' => ucfirst(str_replace('_', ' ', $rule)).' evidence was considered.',
            ])->all();
        $result = [
            'recommendation' => $recommendation->value,
            'score' => $score,
            'contributions' => $contributions,
            'missing_fields' => $missingFields,
            'hard_exclusions' => [],
            'decision_reason_code' => 'triage.test',
            'decision_explanation' => 'Synthetic saved suggestion.',
            'manual_review_required' => true,
        ];
        $evaluation = OpportunityEvaluation::query()->create([
            'workspace_id' => $opportunity->workspace_id,
            'opportunity_id' => $opportunity->id,
            'engine_version' => OpportunityScorer::ENGINE_VERSION,
            'profile_version' => $profile->version,
            'input_sha256' => $input->sha256,
            'profile_snapshot' => $profile->definition,
            'input_snapshot' => $input->snapshot,
            'result' => $result,
            'recommendation' => $recommendation,
            'score' => $score,
        ]);
        $opportunity->update(['review_evaluation_id' => $evaluation->id]);

        return $evaluation;
    }
}
