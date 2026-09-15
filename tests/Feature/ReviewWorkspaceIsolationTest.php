<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_denies_foreign_records_on_every_review_route(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspaceA->id]);
        $foreignOpportunity = Opportunity::factory()->create(['workspace_id' => $workspaceB->id]);

        $this->actingAs($user)->get('/opportunities/'.$foreignOpportunity->id)->assertNotFound();
        $this->actingAs($user)->getJson('/review/v1/opportunities/'.$foreignOpportunity->id)->assertNotFound();
        $this->actingAs($user)->postJson('/review/v1/opportunities/'.$foreignOpportunity->id.'/evaluations')->assertNotFound();

        $this->assertDatabaseCount('opportunity_evaluations', 0);
        $this->assertNull($foreignOpportunity->fresh()->review_evaluation_id);
    }
}
