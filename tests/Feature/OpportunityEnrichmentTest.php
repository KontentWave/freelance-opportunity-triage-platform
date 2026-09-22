<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Domain\Triage\Data\ScoringProfile;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use App\Models\OpportunityEnrichment;
use App\Models\OpportunityEvaluation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpportunityEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_scores_confirmed_details_and_preserves_email_evidence(): void
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
        $emailResult = $evaluation->result;
        $emailInput = $evaluation->input_snapshot;

        $response = $this->actingAs($user)->postJson(
            '/review/v1/opportunities/'.$opportunity->id.'/enrichments',
            [
                'evaluation_id' => $evaluation->id,
                'expected_enrichment_id' => null,
                'full_description' => "  Synthetic scope\r\nwith confirmed terms.  ",
                'overrides' => ['hourly_max' => '40.00'],
            ],
        );

        $enrichmentId = $response
            ->assertOk()
            ->assertJsonPath('data.basis', 'Your confirmed details')
            ->assertJsonPath('data.recommendation', 'APPLY')
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.email_result.recommendation', 'MAYBE')
            ->assertJsonPath('data.email_result.score', 70)
            ->assertJsonPath('data.current_enrichment.revision', 1)
            ->assertJsonPath('data.current_enrichment.full_description', "Synthetic scope\nwith confirmed terms.")
            ->json('data.current_enrichment.id');

        $this->assertDatabaseHas('opportunity_enrichments', [
            'id' => $enrichmentId,
            'workspace_id' => $workspace->id,
            'opportunity_id' => $opportunity->id,
            'evaluation_id' => $evaluation->id,
            'revision' => 1,
        ]);
        $this->assertSame($emailResult, $evaluation->fresh()->result);
        $this->assertSame($emailInput, $evaluation->fresh()->input_snapshot);
        $this->assertDatabaseCount('opportunity_evaluations', 1);
        $this->assertNull($opportunity->fresh()->hourly_max);
    }

    #[Test]
    public function it_saves_against_the_displayed_fallback_evaluation_without_selecting_a_pointer(): void
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
        $historicalProfile = ScoringProfile::fromArray([
            ...$profile->definition,
            'label' => 'Historical test profile',
        ]);
        $historicalEvaluation = app(EvaluateOpportunity::class)->execute(
            $workspace->id,
            $opportunity->id,
            $historicalProfile,
        );
        $displayedEvaluation = app(EvaluateOpportunity::class)->execute($workspace->id, $opportunity->id, $profile);

        $this->assertNull($opportunity->fresh()->review_evaluation_id);
        $this->actingAs($user)
            ->getJson('/review/v1/opportunities/'.$opportunity->id)
            ->assertOk()
            ->assertJsonPath('data.evaluation_id', $displayedEvaluation->id);

        $url = '/review/v1/opportunities/'.$opportunity->id.'/enrichments';
        $payload = [
            'expected_enrichment_id' => null,
            'full_description' => 'Confirmed current details.',
            'overrides' => ['hourly_max' => '40.00'],
        ];

        $this->actingAs($user)->postJson($url, [
            ...$payload,
            'evaluation_id' => $displayedEvaluation->id,
        ])->assertOk()
            ->assertJsonPath('data.evaluation_id', $displayedEvaluation->id);

        $this->actingAs($user)->postJson($url, [
            ...$payload,
            'evaluation_id' => $historicalEvaluation->id,
        ])->assertStatus(409)->assertJsonMissing(['exception']);

        $this->assertDatabaseCount('opportunity_enrichments', 1);
        $this->assertNull($opportunity->fresh()->review_evaluation_id);
    }

    #[Test]
    public function it_changes_no_score_for_description_only_enrichment(): void
    {
        [$user, $opportunity, $evaluation] = $this->reviewContext();

        $this->actingAs($user)
            ->postJson('/review/v1/opportunities/'.$opportunity->id.'/enrichments', [
                'evaluation_id' => $evaluation->id,
                'expected_enrichment_id' => null,
                'full_description' => 'A much more detailed description with no confirmed corrections.',
                'overrides' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', $evaluation->score)
            ->assertJsonPath('data.recommendation', $evaluation->recommendation->value)
            ->assertJsonPath('data.current_enrichment.input', $evaluation->input_snapshot);

        $this->assertSame($evaluation->input_sha256, OpportunityEnrichment::query()->sole()->input_sha256);
        $this->assertSame($evaluation->result, $evaluation->fresh()->result);
    }

    #[Test]
    public function it_preserves_revisions_and_reuses_identical_retries(): void
    {
        [$user, $opportunity, $evaluation] = $this->reviewContext();
        $url = '/review/v1/opportunities/'.$opportunity->id.'/enrichments';
        $payload = fn (?string $expectedId, string $description, string $hourlyMax): array => [
            'evaluation_id' => $evaluation->id,
            'expected_enrichment_id' => $expectedId,
            'full_description' => $description,
            'overrides' => ['hourly_max' => $hourlyMax],
        ];

        $revisionOneId = $this->actingAs($user)->postJson($url, $payload(null, 'Context A', '40.00'))
            ->assertOk()
            ->assertJsonPath('data.current_enrichment.revision', 1)
            ->json('data.current_enrichment.id');
        $revisionTwoId = $this->actingAs($user)->postJson($url, $payload($revisionOneId, 'Context B', '50.00'))
            ->assertOk()
            ->assertJsonPath('data.current_enrichment.revision', 2)
            ->json('data.current_enrichment.id');

        $this->actingAs($user)->postJson($url, $payload($revisionOneId, 'Context B', '50.00'))
            ->assertOk()
            ->assertJsonPath('data.current_enrichment.id', $revisionTwoId)
            ->assertJsonPath('data.current_enrichment.revision', 2);

        $revisionThreeId = $this->actingAs($user)->postJson($url, $payload($revisionTwoId, 'Context A', '40.00'))
            ->assertOk()
            ->assertJsonPath('data.current_enrichment.revision', 3)
            ->json('data.current_enrichment.id');

        $this->assertNotSame($revisionOneId, $revisionThreeId);
        $this->assertSame(
            [
                [1, 'Context A', '40.00'],
                [2, 'Context B', '50.00'],
                [3, 'Context A', '40.00'],
            ],
            OpportunityEnrichment::query()
                ->orderBy('revision')
                ->get()
                ->map(fn (OpportunityEnrichment $enrichment): array => [
                    $enrichment->revision,
                    $enrichment->full_description,
                    $enrichment->overrides['hourly_max'],
                ])->all(),
        );
    }

    #[Test]
    public function it_rejects_stale_base_and_manual_contexts(): void
    {
        [$user, $opportunity, $evaluation] = $this->reviewContext();
        $url = '/review/v1/opportunities/'.$opportunity->id.'/enrichments';
        $payload = [
            'evaluation_id' => $evaluation->id,
            'expected_enrichment_id' => null,
            'full_description' => 'Current details',
            'overrides' => ['hourly_max' => '40.00'],
        ];

        $opportunity->update(['hourly_max' => '35.00']);
        $this->actingAs($user)->postJson($url, $payload)
            ->assertStatus(409)
            ->assertJsonMissing(['exception']);
        $this->assertDatabaseCount('opportunity_enrichments', 0);

        $opportunity->update(['hourly_max' => null]);
        $enrichmentId = $this->actingAs($user)->postJson($url, $payload)
            ->assertOk()
            ->json('data.current_enrichment.id');

        $this->actingAs($user)->postJson($url, [
            ...$payload,
            'full_description' => 'Conflicting stale details',
        ])->assertStatus(409)->assertJsonMissing(['exception']);

        $this->assertDatabaseCount('opportunity_enrichments', 1);
        $this->assertSame($enrichmentId, OpportunityEnrichment::query()->sole()->id);
    }

    #[Test]
    public function it_distinguishes_explicit_unknown_false_empty_and_omitted_overrides(): void
    {
        [$user, $opportunity, $evaluation] = $this->reviewContext();
        $url = '/review/v1/opportunities/'.$opportunity->id.'/enrichments';
        $explicitOverrides = [
            'contract_type' => null,
            'currency' => null,
            'hourly_max' => null,
            'skills' => [],
            'hidden_skill_count' => 0,
            'payment_verified' => false,
            'client_rating' => null,
        ];

        $firstId = $this->actingAs($user)->postJson($url, [
            'evaluation_id' => $evaluation->id,
            'expected_enrichment_id' => null,
            'full_description' => 'Explicit unknown and false values.',
            'overrides' => $explicitOverrides,
        ])->assertOk()
            ->assertJsonPath('data.current_enrichment.overrides', $explicitOverrides)
            ->assertJsonPath('data.current_enrichment.input.contract_type', null)
            ->assertJsonPath('data.current_enrichment.input.currency', null)
            ->assertJsonPath('data.current_enrichment.input.skills', [])
            ->assertJsonPath('data.current_enrichment.input.hidden_skill_count', 0)
            ->assertJsonPath('data.current_enrichment.input.payment_verified', false)
            ->assertJsonPath('data.current_enrichment.input.client_rating', null)
            ->json('data.current_enrichment.id');

        $this->actingAs($user)->postJson($url, [
            'evaluation_id' => $evaluation->id,
            'expected_enrichment_id' => $firstId,
            'full_description' => 'Only the rate is confirmed now.',
            'overrides' => ['hourly_max' => '40.00'],
        ])->assertOk()
            ->assertJsonPath('data.current_enrichment.overrides', ['hourly_max' => '40.00'])
            ->assertJsonPath('data.current_enrichment.input.contract_type', $evaluation->input_snapshot['contract_type'])
            ->assertJsonPath('data.current_enrichment.input.currency', $evaluation->input_snapshot['currency'])
            ->assertJsonPath('data.current_enrichment.input.skills', $evaluation->input_snapshot['skills'])
            ->assertJsonPath('data.current_enrichment.input.payment_verified', $evaluation->input_snapshot['payment_verified'])
            ->assertJsonPath('data.current_enrichment.input.client_rating', $evaluation->input_snapshot['client_rating']);
    }

    /** @return array{User, Opportunity, OpportunityEvaluation} */
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

        return [$user, $opportunity, $evaluation];
    }
}
