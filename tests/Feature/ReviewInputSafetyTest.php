<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewInputSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_invalid_writes_and_escapes_private_text(): void
    {
        [$user, $opportunity, $evaluation] = $this->reviewContext();
        $url = '/review/v1/opportunities/'.$opportunity->id.'/enrichments';
        $valid = [
            'evaluation_id' => $evaluation->id,
            'expected_enrichment_id' => null,
            'full_description' => 'Confirmed details.',
            'overrides' => [],
        ];
        $invalidPayloads = [
            $valid + ['workspace_id' => $opportunity->workspace_id],
            [...$valid, 'overrides' => ['score' => 100]],
            [...$valid, 'full_description' => ''],
            [...$valid, 'full_description' => str_repeat('x', 20_001)],
            [...$valid, 'overrides' => ['contract_type' => 'fixed']],
            [...$valid, 'overrides' => ['hourly_max' => '40.0']],
            [...$valid, 'overrides' => ['client_rating' => '5.01']],
            [...$valid, 'overrides' => ['payment_verified' => 1]],
        ];

        foreach ($invalidPayloads as $payload) {
            $this->actingAs($user)->postJson($url, $payload)->assertUnprocessable();
        }

        $this->actingAs($user)->call(
            'POST',
            $url,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([...$valid, 'full_description' => str_repeat('x', 65_536)], JSON_THROW_ON_ERROR),
        )->assertStatus(413);
        $this->assertDatabaseCount('opportunity_enrichments', 0);

        $privateText = '<script>window.privateData = true</script>';
        $enrichmentId = $this->actingAs($user)->postJson($url, [
            ...$valid,
            'full_description' => $privateText,
        ])->assertOk()->assertJsonPath('data.current_enrichment.full_description', $privateText)
            ->json('data.current_enrichment.id');

        $feedbackUrl = '/review/v1/opportunities/'.$opportunity->id.'/review';
        $feedback = [
            'evaluation_id' => $evaluation->id,
            'enrichment_id' => $enrichmentId,
            'human_label' => 'APPLY',
            'reason_code' => 'economics',
            'notes' => $privateText,
            'outcome' => 'applied',
            'sample_kind' => 'demo',
        ];

        $this->actingAs($user)->putJson($feedbackUrl, $feedback + ['score' => 100])->assertUnprocessable();
        $this->actingAs($user)->putJson($feedbackUrl, [...$feedback, 'human_label' => 'KEEP'])->assertUnprocessable();
        $this->actingAs($user)->putJson($feedbackUrl, [...$feedback, 'outcome' => 'won'])->assertUnprocessable();
        $this->actingAs($user)->putJson($feedbackUrl, [...$feedback, 'notes' => str_repeat('x', 2_001)])->assertUnprocessable();
        $this->actingAs($user)->putJson($feedbackUrl, [...$feedback, 'reason_code' => null])->assertUnprocessable();

        $this->actingAs($user)->putJson($feedbackUrl, $feedback)
            ->assertOk()
            ->assertJsonPath('data.current_review.notes', $privateText);

        $this->assertDatabaseCount('opportunity_enrichments', 1);
        $this->assertDatabaseCount('opportunity_reviews', 1);
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
