<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\OpportunityEnrichment;
use App\Models\OpportunityReview;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\ReviewDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ReviewDemoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_404_for_demo_entry_in_private_mode(): void
    {
        $this->get('/demo')->assertNotFound();
        $this->post('/demo-session')->assertNotFound();
    }

    #[Test]
    public function it_limits_demo_sessions_and_writes_to_synthetic_fixtures(): void
    {
        $this->enableDemoMode();
        $this->seed(ReviewDemoSeeder::class);
        $originalCounts = [
            Workspace::query()->count(),
            User::query()->count(),
            Opportunity::query()->count(),
        ];

        $this->seed(ReviewDemoSeeder::class);

        $this->assertSame([2, 2, 28], $originalCounts);
        $this->assertSame($originalCounts, [
            Workspace::query()->count(),
            User::query()->count(),
            Opportunity::query()->count(),
        ]);
        $this->assertSame(['APPLY', 'MAYBE', 'SKIP'], Opportunity::query()
            ->join('opportunity_evaluations', 'opportunity_evaluations.id', '=', 'opportunities.review_evaluation_id')
            ->distinct()
            ->orderBy('opportunity_evaluations.recommendation')
            ->pluck('opportunity_evaluations.recommendation')
            ->all());
        $this->assertSame(1, Opportunity::query()->whereNull('review_evaluation_id')->count());

        $this->get('/demo')->assertOk()->assertSee('Start demo');
        $this->postJson('/demo-session', ['user_id' => 41002])->assertUnprocessable();
        $this->assertGuest();
        $this->post('/demo-session')->assertRedirect(route('opportunities.index'));
        $this->assertAuthenticatedAs(User::query()->findOrFail(41001));

        $opportunity = Opportunity::query()
            ->whereHas('workspace.users', fn ($query) => $query->whereKey(41001))
            ->whereNotNull('review_evaluation_id')
            ->whereNull('hourly_max')
            ->firstOrFail();
        $enrichmentUrl = '/review/v1/opportunities/'.$opportunity->id.'/enrichments';
        $basePayload = [
            'evaluation_id' => $opportunity->review_evaluation_id,
            'expected_enrichment_id' => null,
        ];

        $this->postJson($enrichmentUrl, $basePayload + [
            'preset_key' => 'confirm_hourly_rate',
            'full_description' => 'forged',
        ])->assertUnprocessable();
        $this->postJson($enrichmentUrl, $basePayload + ['preset_key' => 'unknown'])->assertUnprocessable();
        $enrichmentId = $this->postJson($enrichmentUrl, $basePayload + ['preset_key' => 'confirm_hourly_rate'])
            ->assertOk()
            ->assertJsonPath('data.current_enrichment.overrides.hourly_max', '40.00')
            ->json('data.current_enrichment.id');

        $feedbackUrl = '/review/v1/opportunities/'.$opportunity->id.'/review';
        $feedback = [
            'evaluation_id' => $opportunity->review_evaluation_id,
            'enrichment_id' => $enrichmentId,
            'human_label' => 'APPLY',
            'reason_code' => 'economics',
            'notes' => null,
            'outcome' => 'applied',
            'sample_kind' => 'demo',
        ];

        $this->putJson($feedbackUrl, [...$feedback, 'notes' => 'visitor text'])->assertUnprocessable();
        $this->putJson($feedbackUrl, [...$feedback, 'sample_kind' => 'real'])->assertUnprocessable();
        $this->putJson($feedbackUrl, $feedback)->assertOk()->assertJsonPath('data.current_review.sample_kind', 'demo');

        $this->assertDatabaseCount('opportunity_enrichments', 1);
        $this->assertSame('demo', OpportunityReview::query()->where('enrichment_id', $enrichmentId)->value('sample_kind'));
        $this->assertSame('40.00', OpportunityEnrichment::query()->findOrFail($enrichmentId)->overrides['hourly_max']);
    }

    #[Test]
    public function it_refuses_demo_seeding_when_mode_is_unsafe(): void
    {
        $this->expectException(RuntimeException::class);

        $this->seed(ReviewDemoSeeder::class);
    }

    #[Test]
    public function it_refuses_to_overwrite_unrelated_records(): void
    {
        $this->enableDemoMode();
        Workspace::factory()->create(['name' => 'Unrelated workspace']);

        $this->expectException(RuntimeException::class);

        $this->seed(ReviewDemoSeeder::class);
    }

    #[Test]
    public function it_refuses_to_adopt_a_same_name_workspace(): void
    {
        $this->enableDemoMode();
        Workspace::factory()->create(['name' => 'Synthetic Review Workspace']);

        $this->expectException(RuntimeException::class);

        $this->seed(ReviewDemoSeeder::class);
    }

    private function enableDemoMode(): void
    {
        config()->set('opportunity_review.mode', 'demo');
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        config()->set('opportunity_review.demo_user_id', 41001);
        config()->set('opportunity_mailbox.enabled', false);
    }
}
