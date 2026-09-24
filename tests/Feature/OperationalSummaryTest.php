<?php

namespace Tests\Feature;

use App\Application\Operations\BuildOperationalSummary;
use App\Application\Review\ListReviewOpportunities;
use App\Application\Triage\BuildOpportunityTriageInput;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\OpportunityScorer;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\EmailImport;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Opportunity;
use App\Models\OpportunityEnrichment;
use App\Models\OpportunityEvaluation;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_empty_state_without_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
        $workspace = Workspace::factory()->create();
        $profile = app(LocalScoringProfileLoader::class)->load(resource_path('triage/profiles/demo-v1.json'));

        $this->assertSame([
            'schema_version' => 1,
            'generated_at' => '2026-09-24T12:00:00+00:00',
            'window_hours' => 24,
            'mailbox' => [
                'completed_runs' => 0,
                'processed_count' => 0,
                'duplicate_count' => 0,
                'quarantined_count' => 0,
                'last_successful_poll_age_seconds' => null,
                'unfinished_count' => 0,
                'oldest_unfinished_age_seconds' => null,
            ],
            'parser' => ['quarantined_imports' => 0, 'error_counts' => []],
            'triage' => ['APPLY' => 0, 'MAYBE' => 0, 'SKIP' => 0, 'UNSCORED' => 0],
        ], json_decode(json_encode(app(BuildOperationalSummary::class)->execute($workspace->id, $profile, now('UTC')->toImmutable()), JSON_THROW_ON_ERROR), true));

        $this->assertSame(0, $workspace->opportunities()->count());

        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $this->assertSame(0, Artisan::call('opportunity:summary', ['--workspace' => $workspace->id, '--json' => true]));
        $output = Artisan::output();
        $this->assertSame(0, json_decode($output, true)['triage']['UNSCORED']);
        $this->assertStringContainsString('"error_counts":{}', $output);
        $this->assertSame(0, DB::table('opportunity_evaluations')->count());
    }

    #[Test]
    public function it_reports_workspace_scoped_counts_and_ages(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
        $workspace = Workspace::factory()->create();
        $foreign = Workspace::factory()->create();
        $start = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC');

        foreach ([
            [$workspace, 'partial', $start, 4, 2, 1, 'former'],
            [$workspace, 'partial', $end, 3, 1, 2, 'new'],
            [$workspace, 'failed', $end->subHour(), 5, 0, 1, 'new'],
            [$workspace, 'succeeded', $start->subSeconds(1), 100, 100, 100, 'former'],
            [$workspace, 'succeeded', $end->addSecond(), 100, 100, 100, 'new'],
            [$workspace, 'skipped_overlap', $end, 100, 100, 100, 'new'],
            [$foreign, 'succeeded', $end, 100, 100, 100, 'new'],
        ] as [$owner, $status, $finished, $processed, $duplicate, $quarantined, $key]) {
            MailboxRun::query()->create([
                'workspace_id' => $owner->id, 'mailbox_key' => $key, 'status' => $status,
                'started_at' => $finished->subMinute(), 'finished_at' => $finished,
                'processed_count' => $processed, 'duplicate_count' => $duplicate,
                'quarantined_count' => $quarantined,
            ]);
        }
        MailboxRun::query()->create([
            'workspace_id' => $workspace->id, 'mailbox_key' => 'new', 'status' => 'running',
            'started_at' => $start, 'finished_at' => null,
            'processed_count' => 100,
        ]);
        foreach ([
            [$workspace, 'pending', $start, 'former'],
            [$workspace, 'retry_wait', $end->subSeconds(90), 'new'],
            [$workspace, 'imported', $start->subHour(), 'former'],
            [$workspace, 'pending', $end->addSecond(), 'new'],
            [$foreign, 'pending', $start->subHour(), 'new'],
        ] as $index => [$owner, $status, $firstSeen, $key]) {
            MailboxMessage::query()->create([
                'workspace_id' => $owner->id, 'mailbox_key' => $key, 'uid_validity' => 1,
                'message_uid' => $index + 1, 'status' => $status, 'first_seen_at' => $firstSeen,
            ]);
        }
        foreach ([
            [$workspace, 'quarantined', $start, 'missing_job_id'],
            [$workspace, 'quarantined', $end, 'missing_job_id'],
            [$workspace, 'quarantined', $end, 'private-secret'],
            [$workspace, 'quarantined', $end, null],
            [$workspace, 'quarantined', $end, 'email_too_large'],
            [$workspace, 'quarantined', $start->subSecond(), 'unsupported_sender'],
            [$workspace, 'imported', $end, 'unsupported_sender'],
            [$foreign, 'quarantined', $end, 'missing_title'],
        ] as $index => [$owner, $status, $imported, $code]) {
            EmailImport::query()->create([
                'workspace_id' => $owner->id, 'message_id' => 'synthetic-'.$index,
                'content_sha256' => hash('sha256', 'synthetic-'.$index),
                'status' => $status, 'error_code' => $code, 'imported_at' => $imported,
            ]);
        }
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $before = [
            'runs' => $this->snapshot('mailbox_runs'),
            'messages' => $this->snapshot('mailbox_messages'),
            'imports' => $this->snapshot('email_imports'),
        ];

        $this->assertSame(0, Artisan::call('opportunity:summary', ['--workspace' => $workspace->id, '--json' => true]));
        $report = json_decode(Artisan::output(), true);

        $this->assertSame('2026-09-24T12:00:00+00:00', $report['generated_at']);
        $this->assertSame([
            'completed_runs' => 3, 'processed_count' => 12, 'duplicate_count' => 3,
            'quarantined_count' => 4, 'last_successful_poll_age_seconds' => 86401,
            'unfinished_count' => 2, 'oldest_unfinished_age_seconds' => 86400,
        ], $report['mailbox']);
        $this->assertSame(['quarantined_imports' => 5, 'error_counts' => [
            'email_too_large' => 1, 'missing_job_id' => 2, 'unknown_error' => 2,
        ]], $report['parser']);
        $this->assertSame($before['runs'], $this->snapshot('mailbox_runs'));
        $this->assertSame($before['messages'], $this->snapshot('mailbox_messages'));
        $this->assertSame($before['imports'], $this->snapshot('email_imports'));
        $this->assertStringNotContainsString('private-secret', Artisan::output());

        $this->assertSame(0, Artisan::call('opportunity:summary', ['--workspace' => $workspace->id]));
        $text = Artisan::output();
        $this->assertStringContainsString('mailbox.last_successful_poll_age_seconds: 86401', $text);
        $this->assertStringContainsString('parser.error_counts: {"email_too_large":1,"missing_job_id":2,"unknown_error":2}', $text);
    }

    #[Test]
    public function it_counts_each_current_queue_suggestion_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
        $workspace = Workspace::factory()->create();
        $foreign = Workspace::factory()->create();
        $profile = app(LocalScoringProfileLoader::class)->load(resource_path('triage/profiles/demo-v1.json'));
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));

        $selected = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $older = $this->evaluation($selected, $profile, 'APPLY', '2026-09-20 12:00:00');
        $this->evaluation($selected, $profile, 'SKIP', '2026-09-21 12:00:00');
        $selected->forceFill(['review_evaluation_id' => $older->id])->save();
        $fallback = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $this->evaluation($fallback, $profile, 'SKIP', '2026-09-20 12:00:00');
        $latest = $this->evaluation($fallback, $profile, 'MAYBE', '2026-09-21 12:00:00');
        $fallback->forceFill(['review_evaluation_id' => $older->id])->save();
        $this->enrichment($latest, 1, 'SKIP');
        $this->enrichment($latest, 2, 'APPLY');
        $skip = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $skipEvaluation = $this->evaluation($skip, $profile, 'MAYBE', '2026-09-22 12:00:00');
        $this->enrichment($skipEvaluation, 1, 'SKIP');
        $maybe = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $this->evaluation($maybe, $profile, 'MAYBE', '2026-09-22 12:00:00');
        $unscored = Opportunity::factory()->create(['workspace_id' => $workspace->id]);
        $this->evaluation($unscored, $profile, 'SKIP', '2026-09-22 12:00:00', 'old-engine');
        $foreignOpportunity = Opportunity::factory()->create(['workspace_id' => $foreign->id]);
        $this->evaluation($foreignOpportunity, $profile, 'SKIP', '2026-09-22 12:00:00');
        $displayed = app(ListReviewOpportunities::class)
            ->query($workspace->id, $profile)->where('opportunities.id', $fallback->id)->first();
        $this->assertSame($latest->id, $displayed->getAttribute('displayed_evaluation_id'));
        $this->assertSame('APPLY', $displayed->getAttribute('displayed_recommendation'));
        $before = $this->snapshot('opportunity_evaluations');
        $beforeEnrichments = $this->snapshot('opportunity_enrichments');

        $this->assertSame(0, Artisan::call('opportunity:summary', ['--workspace' => $workspace->id, '--json' => true]));

        $this->assertSame(['APPLY' => 2, 'MAYBE' => 1, 'SKIP' => 1, 'UNSCORED' => 1], json_decode(Artisan::output(), true)['triage']);
        $this->assertSame($before, $this->snapshot('opportunity_evaluations'));
        $this->assertSame($beforeEnrichments, $this->snapshot('opportunity_enrichments'));
        $this->assertSame($older->id, $fallback->fresh()->review_evaluation_id);
    }

    #[Test]
    public function it_rejects_invalid_context_without_disclosure(): void
    {
        $workspace = Workspace::factory()->create();
        foreach ([[], ['--workspace' => 'invalid'], ['--workspace' => Workspace::factory()->create()->id.'extra']] as $arguments) {
            $this->assertSame(1, Artisan::call('opportunity:summary', ['--json' => true, ...$arguments]));
            $this->assertSame(['schema_version' => 1, 'error_code' => 'summary.invalid_workspace'], json_decode(Artisan::output(), true));
        }
        $this->assertSame(1, Artisan::call('opportunity:summary', ['--json' => true, '--workspace' => '01J00000000000000000000000']));
        $this->assertSame(['schema_version' => 1, 'error_code' => 'summary.invalid_workspace'], json_decode(Artisan::output(), true));
        config()->set('opportunity_review.profile_path', '/private/nonexistent-profile.json');
        $this->assertSame(1, Artisan::call('opportunity:summary', ['--json' => true, '--workspace' => $workspace->id]));
        $this->assertSame(['schema_version' => 1, 'error_code' => 'summary.profile_unavailable'], json_decode(Artisan::output(), true));
        $this->assertStringNotContainsString('/private/', Artisan::output());

    }

    #[Test]
    public function it_hides_unexpected_operational_failures(): void
    {
        $workspace = Workspace::factory()->create();
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $this->app->instance(LocalScoringProfileLoader::class, new class extends LocalScoringProfileLoader
        {
            public function load(mixed $profilePath): ScoringProfile
            {
                throw new \RuntimeException('private connection settings');
            }
        });
        $this->assertSame(1, Artisan::call('opportunity:summary', ['--json' => true, '--workspace' => $workspace->id]));
        $this->assertSame(['schema_version' => 1, 'error_code' => 'summary.unavailable'], json_decode(Artisan::output(), true));
        $this->assertStringNotContainsString('private connection settings', Artisan::output());
    }

    private function evaluation(Opportunity $opportunity, ScoringProfile $profile, string $recommendation, string $createdAt, string $engine = OpportunityScorer::ENGINE_VERSION): OpportunityEvaluation
    {
        $input = app(BuildOpportunityTriageInput::class)->execute($opportunity);
        $result = ['recommendation' => $recommendation, 'score' => 50, 'contributions' => [], 'missing_fields' => [],
            'hard_exclusions' => [], 'decision_reason_code' => 'triage.test', 'decision_explanation' => 'Synthetic.', 'manual_review_required' => true];

        return OpportunityEvaluation::query()->forceCreate([
            'workspace_id' => $opportunity->workspace_id, 'opportunity_id' => $opportunity->id,
            'engine_version' => $engine, 'profile_version' => $profile->version,
            'input_sha256' => hash('sha256', $createdAt.$engine), 'profile_snapshot' => $profile->definition,
            'input_snapshot' => $input->snapshot, 'result' => $result,
            'recommendation' => $recommendation, 'score' => 50, 'created_at' => $createdAt,
        ]);
    }

    private function enrichment(OpportunityEvaluation $evaluation, int $revision, string $recommendation): void
    {
        OpportunityEnrichment::query()->create([
            'workspace_id' => $evaluation->workspace_id, 'opportunity_id' => $evaluation->opportunity_id,
            'evaluation_id' => $evaluation->id, 'revision' => $revision,
            'full_description' => 'Synthetic details.', 'overrides' => [], 'input_snapshot' => $evaluation->input_snapshot,
            'result' => [...$evaluation->result, 'recommendation' => $recommendation],
            'payload_sha256' => hash('sha256', (string) $revision), 'input_sha256' => $evaluation->input_sha256,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }
}
