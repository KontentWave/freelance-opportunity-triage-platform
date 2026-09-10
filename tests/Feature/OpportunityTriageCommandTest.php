<?php

namespace Tests\Feature;

use App\Application\Triage\EvaluateOpportunity;
use App\Application\Triage\RecordOpportunityReview;
use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Triage\Data\ScoringProfile;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class OpportunityTriageCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_outputs_safe_json_without_resolving_the_mailbox_client(): void
    {
        $opportunity = $this->opportunity();
        $this->app->bind(MailboxClient::class, fn () => throw new RuntimeException('Mailbox client resolved.'));
        Http::preventStrayRequests();

        $exitCode = Artisan::call('opportunity:triage', [
            'opportunity' => $opportunity->id,
            '--workspace' => $opportunity->workspace_id,
            '--profile' => resource_path('triage/profiles/demo-v1.json'),
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame($opportunity->id, $payload['opportunity_id']);
        $this->assertSame('triage-v1', $payload['engine_version']);
        $this->assertSame('APPLY', $payload['recommendation']);
        $this->assertSame(100, $payload['score']);
        $this->assertTrue($payload['result']['manual_review_required']);
        $this->assertCount(4, $payload['result']['contributions']);
        $this->assertStringContainsString('full opportunity scope', $payload['reminder']);
        $this->assertStringContainsString('credible delivery capability', $payload['reminder']);
        $this->assertStringContainsString('20 hours/week', $payload['reminder']);
        $this->assertStringNotContainsString($opportunity->title, Artisan::output());
        $this->assertSame(1, OpportunityEvaluation::query()->count());
    }

    #[Test]
    public function it_outputs_a_readable_explanation_and_required_reminder(): void
    {
        $opportunity = $this->opportunity();

        $this->artisan('opportunity:triage', [
            'opportunity' => $opportunity->id,
            '--workspace' => $opportunity->workspace_id,
            '--profile' => resource_path('triage/profiles/demo-v1.json'),
        ])
            ->expectsOutputToContain('recommendation: APPLY')
            ->expectsOutputToContain('skill_match: matched (40/40)')
            ->expectsOutputToContain('manual_review_required: true')
            ->expectsOutputToContain('full opportunity scope')
            ->expectsOutputToContain('credible delivery capability')
            ->expectsOutputToContain('20 hours/week')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_invalid_profiles_before_evaluation_writes(): void
    {
        $workspace = Workspace::factory()->create();
        $profilePath = $this->temporaryFile('{"schema_version":2}');
        $opportunityQueries = 0;
        DB::listen(function ($query) use (&$opportunityQueries): void {
            if (str_contains($query->sql, 'from `opportunities`')) {
                $opportunityQueries++;
            }
        });

        try {
            $this->artisan('opportunity:triage', [
                'opportunity' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                '--workspace' => $workspace->id,
                '--profile' => $profilePath,
                '--json' => true,
            ])
                ->expectsOutput('{"error_code":"triage.profile_invalid"}')
                ->assertExitCode(1);
        } finally {
            @unlink($profilePath);
        }

        $this->assertSame(0, $opportunityQueries);
        $this->assertSame(0, OpportunityEvaluation::query()->count());
    }

    #[Test]
    public function it_rejects_stream_wrappers_and_oversized_profiles_with_safe_errors(): void
    {
        $workspace = Workspace::factory()->create();
        $oversizedPath = $this->temporaryFile(str_repeat('x', 65_537));

        try {
            foreach (['php://memory', $oversizedPath] as $path) {
                $exitCode = Artisan::call('opportunity:triage', [
                    'opportunity' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    '--workspace' => $workspace->id,
                    '--profile' => $path,
                    '--json' => true,
                ]);

                $this->assertSame(1, $exitCode);
                $this->assertSame(
                    ['error_code' => 'triage.profile_invalid'],
                    json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR),
                );
            }
        } finally {
            @unlink($oversizedPath);
        }

        $this->assertSame(0, OpportunityEvaluation::query()->count());
    }

    #[Test]
    public function it_returns_the_same_safe_not_found_error_for_foreign_and_missing_opportunities(): void
    {
        $opportunity = $this->opportunity();
        $foreignWorkspace = Workspace::factory()->create();

        foreach ([$opportunity->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $opportunityId) {
            $exitCode = Artisan::call('opportunity:triage', [
                'opportunity' => $opportunityId,
                '--workspace' => $foreignWorkspace->id,
                '--profile' => resource_path('triage/profiles/demo-v1.json'),
                '--json' => true,
            ]);

            $this->assertSame(1, $exitCode);
            $this->assertSame(
                ['error_code' => 'triage.not_found'],
                json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR),
            );
        }

        $this->assertSame(0, OpportunityEvaluation::query()->count());
    }

    #[Test]
    public function it_records_a_review_with_safe_json_without_mailbox_or_http_access(): void
    {
        $evaluation = $this->evaluation();
        $this->app->bind(MailboxClient::class, fn () => throw new RuntimeException('Mailbox client resolved.'));
        Http::preventStrayRequests();

        $exitCode = Artisan::call('opportunity:review', [
            'evaluation' => $evaluation->id,
            'label' => 'SKIP',
            '--workspace' => $evaluation->workspace_id,
            '--reason' => 'fit',
            '--sample-kind' => 'real',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame($evaluation->id, $payload['evaluation_id']);
        $this->assertSame('SKIP', $payload['human_label']);
        $this->assertSame('fit', $payload['reason_code']);
        $this->assertSame('real', $payload['sample_kind']);
        $this->assertStringContainsString('20 hours/week', $payload['reminder']);
        $this->assertArrayNotHasKey('result', $payload);
    }

    #[Test]
    public function it_reports_only_aggregates_with_scope_and_reminders_without_mailbox_or_http_access(): void
    {
        $evaluation = $this->evaluation();
        $this->app->bind(MailboxClient::class, fn () => throw new RuntimeException('Mailbox client resolved.'));
        Http::preventStrayRequests();
        $cohortPath = $this->temporaryFile((string) json_encode([$evaluation->id]));

        try {
            $exitCode = Artisan::call('opportunity:triage-report', [
                '--workspace' => $evaluation->workspace_id,
                '--evaluations' => $cohortPath,
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($cohortPath);
        }

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['n_selected']);
        $this->assertSame('DEMO_ONLY', $payload['data_status']);
        $this->assertNull($payload['targets_met']);
        $this->assertStringContainsString('selected supported imports only', $payload['scope_statement']);
        $this->assertStringContainsString('20 hours/week', $payload['reminder']);
        $this->assertArrayNotHasKey('evaluation_id', $payload);
        $this->assertArrayNotHasKey('opportunity_id', $payload);
    }

    #[Test]
    public function it_displays_defined_report_percentages_with_two_decimal_places(): void
    {
        $evaluation = $this->evaluation();
        app(RecordOpportunityReview::class)->execute(
            $evaluation->workspace_id,
            $evaluation->id,
            'APPLY',
            null,
            'real',
        );
        $cohortPath = $this->temporaryFile((string) json_encode([$evaluation->id]));

        try {
            $this->artisan('opportunity:triage-report', [
                '--workspace' => $evaluation->workspace_id,
                '--evaluations' => $cohortPath,
            ])
                ->expectsOutputToContain('skip_rate_percent: 0.00')
                ->assertExitCode(0);
        } finally {
            @unlink($cohortPath);
        }
    }

    private function opportunity(): Opportunity
    {
        $opportunity = Opportunity::factory()->create();
        $opportunity->skills()->create([
            'name' => 'Django',
            'position' => 0,
        ]);

        return $opportunity;
    }

    private function evaluation(): OpportunityEvaluation
    {
        $opportunity = $this->opportunity();
        $profile = ScoringProfile::fromArray(
            json_decode(
                (string) file_get_contents(resource_path('triage/profiles/demo-v1.json')),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        return app(EvaluateOpportunity::class)->execute(
            $opportunity->workspace_id,
            $opportunity->id,
            $profile,
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'triage-profile-');
        $this->assertNotFalse($path);
        $this->assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }
}
