<?php

namespace Tests\Feature;

use App\Models\EmailImport;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpportunitySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_enforces_workspace_scoped_opportunity_uniqueness(): void
    {
        $workspace = Workspace::factory()->create();
        Opportunity::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'upwork_email',
            'external_id' => '200000000000000000001',
        ]);

        $this->expectException(QueryException::class);

        Opportunity::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'upwork_email',
            'external_id' => '200000000000000000001',
        ]);
    }

    #[Test]
    public function it_enforces_workspace_scoped_message_and_hash_idempotency(): void
    {
        $workspace = Workspace::factory()->create();
        $opportunity = Opportunity::factory()->create(['workspace_id' => $workspace->id]);

        EmailImport::query()->create([
            'workspace_id' => $workspace->id,
            'opportunity_id' => $opportunity->id,
            'message_id' => 'fixture-1@example.test',
            'content_sha256' => str_repeat('a', 64),
            'status' => 'imported',
            'error_code' => null,
            'imported_at' => now(),
        ]);

        try {
            EmailImport::query()->create([
                'workspace_id' => $workspace->id,
                'opportunity_id' => $opportunity->id,
                'message_id' => 'fixture-1@example.test',
                'content_sha256' => str_repeat('b', 64),
                'status' => 'duplicate',
                'error_code' => null,
                'imported_at' => now(),
            ]);

            $this->fail('Expected unique message_id constraint to fail.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);

        EmailImport::query()->create([
            'workspace_id' => $workspace->id,
            'opportunity_id' => $opportunity->id,
            'message_id' => 'fixture-2@example.test',
            'content_sha256' => str_repeat('a', 64),
            'status' => 'duplicate',
            'error_code' => null,
            'imported_at' => now(),
        ]);
    }

    #[Test]
    public function it_cascades_workspace_deletion_without_cross_workspace_effects(): void
    {
        $firstWorkspace = Workspace::factory()->create();
        $secondWorkspace = Workspace::factory()->create();
        $firstOpportunity = Opportunity::factory()->create(['workspace_id' => $firstWorkspace->id]);
        $secondOpportunity = Opportunity::factory()->create(['workspace_id' => $secondWorkspace->id]);

        $firstOpportunity->skills()->create([
            'name' => 'Project Management',
            'position' => 0,
        ]);
        $secondOpportunity->skills()->create([
            'name' => 'Operations',
            'position' => 0,
        ]);

        EmailImport::query()->create([
            'workspace_id' => $firstWorkspace->id,
            'opportunity_id' => $firstOpportunity->id,
            'message_id' => 'fixture-1@example.test',
            'content_sha256' => str_repeat('a', 64),
            'status' => 'imported',
            'error_code' => null,
            'imported_at' => now(),
        ]);
        EmailImport::query()->create([
            'workspace_id' => $secondWorkspace->id,
            'opportunity_id' => $secondOpportunity->id,
            'message_id' => 'fixture-2@example.test',
            'content_sha256' => str_repeat('b', 64),
            'status' => 'imported',
            'error_code' => null,
            'imported_at' => now(),
        ]);

        $firstWorkspace->delete();

        $this->assertDatabaseMissing('workspaces', ['id' => $firstWorkspace->id]);
        $this->assertDatabaseMissing('opportunities', ['id' => $firstOpportunity->id]);
        $this->assertDatabaseMissing('opportunity_skills', ['opportunity_id' => $firstOpportunity->id]);
        $this->assertDatabaseMissing('email_imports', ['workspace_id' => $firstWorkspace->id]);

        $this->assertDatabaseHas('workspaces', ['id' => $secondWorkspace->id]);
        $this->assertDatabaseHas('opportunities', ['id' => $secondOpportunity->id]);
        $this->assertDatabaseHas('opportunity_skills', ['opportunity_id' => $secondOpportunity->id]);
        $this->assertDatabaseHas('email_imports', ['workspace_id' => $secondWorkspace->id]);
    }
}
