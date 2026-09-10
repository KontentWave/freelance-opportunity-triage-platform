<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Domain\Triage\Data\ScoringProfile;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EvaluateOpportunityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function it_retrieves_the_matching_evaluation_after_a_concurrent_duplicate_insert(): void
    {
        $opportunity = Opportunity::factory()->create();
        $opportunity->skills()->create(['name' => 'Django', 'position' => 0]);
        $insertedEvaluationId = (string) Str::ulid();
        $simulateConcurrentInsert = true;
        $defaultConnection = (string) config('database.default');
        $connectionConfiguration = config('database.connections.'.$defaultConnection);
        $this->assertIsArray($connectionConfiguration);
        config(['database.connections.triage_concurrent' => $connectionConfiguration]);
        OpportunityEvaluation::creating(function (OpportunityEvaluation $evaluation) use (
            &$simulateConcurrentInsert,
            $insertedEvaluationId,
        ): void {
            if (! $simulateConcurrentInsert) {
                return;
            }

            $simulateConcurrentInsert = false;
            DB::connection('triage_concurrent')->table('opportunity_evaluations')->insert([
                'id' => $insertedEvaluationId,
                'workspace_id' => $evaluation->workspace_id,
                'opportunity_id' => $evaluation->opportunity_id,
                'engine_version' => $evaluation->engine_version,
                'profile_version' => $evaluation->profile_version,
                'input_sha256' => $evaluation->input_sha256,
                'profile_snapshot' => json_encode($evaluation->profile_snapshot, JSON_THROW_ON_ERROR),
                'input_snapshot' => json_encode($evaluation->input_snapshot, JSON_THROW_ON_ERROR),
                'result' => json_encode($evaluation->result, JSON_THROW_ON_ERROR),
                'recommendation' => $evaluation->recommendation->value,
                'score' => $evaluation->score,
                'created_at' => now(),
            ]);
        });

        $evaluation = app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $this->profile(),
        );
        DB::purge('triage_concurrent');

        $this->assertSame($insertedEvaluationId, $evaluation->id);
        $this->assertSame(1, OpportunityEvaluation::query()->count());
    }

    private function profile(): ScoringProfile
    {
        return ScoringProfile::fromArray([
            'schema_version' => 1,
            'label' => 'Synthetic demo profile',
            'purpose' => 'demo',
            'minimum_hourly_usd' => '20.00',
            'preferred_skills' => ['django'],
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
