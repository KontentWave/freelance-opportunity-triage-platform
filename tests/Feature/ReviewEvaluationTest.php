<?php

namespace Tests\Feature;

use App\Application\Review\ShowReviewOpportunity;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewEvaluationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_evaluates_explicitly_and_reuses_unchanged_results(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $opportunity = Opportunity::factory()->create([
            'workspace_id' => $workspace->id,
            'hourly_max' => '40.00',
        ]);
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);

        $this->actingAs($user)->getJson('/review/v1/opportunities/'.$opportunity->id)
            ->assertOk()
            ->assertJsonPath('data.recommendation', 'UNSCORED');
        $this->assertDatabaseCount('opportunity_evaluations', 0);

        $firstResponse = $this->actingAs($user)
            ->postJson('/review/v1/opportunities/'.$opportunity->id.'/evaluations');
        $firstEvaluation = OpportunityEvaluation::query()
            ->where('opportunity_id', $opportunity->id)
            ->sole();
        $profile = app(LocalScoringProfileLoader::class)->load(config('opportunity_review.profile_path'));

        $this->assertSame($firstEvaluation->id, $opportunity->fresh()->review_evaluation_id);
        $this->assertSame($profile->version, $firstEvaluation->profile_version);
        $this->assertSame('APPLY', $firstEvaluation->recommendation->value);
        $this->assertSame(
            $firstEvaluation->id,
            app(ShowReviewOpportunity::class)
                ->execute($workspace->id, $opportunity->id, $profile)
                ->getAttribute('displayed_evaluation_id'),
        );

        $firstId = $firstResponse
            ->assertOk()
            ->assertJsonPath('data.recommendation', 'APPLY')
            ->assertJsonPath('data.score', 100)
            ->json('data.evaluation_id');

        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$opportunity->id.'/evaluations')
            ->assertOk()
            ->assertJsonPath('data.evaluation_id', $firstId);

        $opportunity->update(['hourly_max' => '10.00']);
        $secondId = $this->actingAs($user)
            ->postJson('/review/v1/opportunities/'.$opportunity->id.'/evaluations')
            ->assertOk()
            ->assertJsonPath('data.recommendation', 'SKIP')
            ->json('data.evaluation_id');

        $this->assertNotSame($firstId, $secondId);
        $opportunity->update(['hourly_max' => '40.00']);

        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$opportunity->id.'/evaluations')
            ->assertOk()
            ->assertJsonPath('data.evaluation_id', $firstId)
            ->assertJsonPath('data.recommendation', 'APPLY');

        $this->assertDatabaseCount('opportunity_evaluations', 2);
        $this->assertSame($firstId, $opportunity->fresh()->review_evaluation_id);
    }

    #[Test]
    public function it_rejects_client_controlled_evaluation_inputs(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user)
            ->postJson('/review/v1/opportunities/'.$opportunity->id.'/evaluations', [
                'workspace_id' => Workspace::factory()->create()->id,
                'profile_path' => '/private/profile.json',
                'score' => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonMissing(['profile_path' => '/private/profile.json']);

        $this->assertDatabaseCount('opportunity_evaluations', 0);
        $this->assertNull($opportunity->fresh()->review_evaluation_id);
    }
}
