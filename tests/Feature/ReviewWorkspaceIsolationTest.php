<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_denies_foreign_records_on_every_review_route(): void
    {
        Http::preventStrayRequests();
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspaceA->id]);
        $foreignUser = User::factory()->create(['workspace_id' => $workspaceB->id]);
        $opportunity = Opportunity::factory()->create(['workspace_id' => $workspaceA->id]);
        $foreignOpportunity = Opportunity::factory()->create(['workspace_id' => $workspaceB->id]);
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);
        $foreignOpportunity->skills()->create(['name' => 'Django', 'position' => 0]);
        $profile = app(LocalScoringProfileLoader::class)->load(config('opportunity_review.profile_path'));
        $evaluation = app(EvaluateOpportunity::class)->execute($workspaceA->id, $opportunity->id, $profile);
        $foreignEvaluation = app(EvaluateOpportunity::class)->execute($workspaceB->id, $foreignOpportunity->id, $profile);
        $opportunity->forceFill(['review_evaluation_id' => $evaluation->id])->save();
        $foreignOpportunity->forceFill(['review_evaluation_id' => $foreignEvaluation->id])->save();
        $foreignEnrichmentId = $this->actingAs($foreignUser)->postJson(
            '/review/v1/opportunities/'.$foreignOpportunity->id.'/enrichments',
            [
                'evaluation_id' => $foreignEvaluation->id,
                'expected_enrichment_id' => null,
                'full_description' => 'Foreign private details.',
                'overrides' => [],
            ],
        )->assertOk()->json('data.current_enrichment.id');

        $this->actingAs($user)->get('/opportunities/'.$foreignOpportunity->id)->assertNotFound();
        $this->actingAs($user)->getJson('/review/v1/opportunities/'.$foreignOpportunity->id)->assertNotFound();
        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$foreignOpportunity->id.'/evaluations')->assertNotFound();
        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$foreignOpportunity->id.'/enrichments', [
            'evaluation_id' => $foreignEvaluation->id,
            'expected_enrichment_id' => $foreignEnrichmentId,
            'full_description' => 'Attempted overwrite.',
            'overrides' => [],
        ])->assertNotFound();
        $this->actingAs($user)->putJson('/review/v1/opportunities/'.$foreignOpportunity->id.'/review', [
            'evaluation_id' => $foreignEvaluation->id,
            'enrichment_id' => $foreignEnrichmentId,
            'human_label' => $foreignEvaluation->recommendation->value,
            'reason_code' => null,
            'notes' => null,
            'outcome' => null,
            'sample_kind' => 'demo',
        ])->assertNotFound();

        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$opportunity->id.'/enrichments', [
            'evaluation_id' => $foreignEvaluation->id,
            'expected_enrichment_id' => null,
            'full_description' => 'Foreign nested evaluation.',
            'overrides' => [],
        ])->assertNotFound();
        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$opportunity->id.'/enrichments', [
            'evaluation_id' => $evaluation->id,
            'expected_enrichment_id' => $foreignEnrichmentId,
            'full_description' => 'Foreign nested enrichment.',
            'overrides' => [],
        ])->assertNotFound();
        $this->actingAs($user)->putJson('/review/v1/opportunities/'.$opportunity->id.'/review', [
            'evaluation_id' => $evaluation->id,
            'enrichment_id' => $foreignEnrichmentId,
            'human_label' => $evaluation->recommendation->value,
            'reason_code' => null,
            'notes' => null,
            'outcome' => null,
            'sample_kind' => 'demo',
        ])->assertNotFound();

        $this->assertDatabaseCount('opportunity_evaluations', 2);
        $this->assertDatabaseCount('opportunity_enrichments', 1);
        $this->assertDatabaseCount('opportunity_reviews', 0);
    }
}
