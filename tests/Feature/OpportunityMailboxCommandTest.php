<?php

namespace Tests\Feature;

use App\Application\Mailbox\PollOpportunityMailbox;
use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Data\DiscoveredMailboxBatch;
use App\Domain\Mailbox\Data\MailboxMessageReference;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Enums\MailboxMessageStatus;
use App\Domain\Mailbox\Enums\MailboxRunStatus;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Fakes\FakeMailboxClient;
use Tests\TestCase;
use Throwable;

final class OpportunityMailboxCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_a_safe_successful_connectivity_check(): void
    {
        $workspace = Workspace::factory()->create();
        $mailboxClient = new FakeMailboxClient;
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $this->artisan('opportunity:mailbox-check')
            ->expectsOutputToContain('status: succeeded')
            ->doesntExpectOutputToContain('mailbox.invalid')
            ->doesntExpectOutputToContain('fixture-user')
            ->doesntExpectOutputToContain('fixture-password')
            ->assertExitCode(0);

        $this->assertSame(1, $mailboxClient->probeCallCount);
        $this->assertSame(1, $mailboxClient->closeCallCount);
        $this->assertSame([], $mailboxClient->discoveryCursors);
        $this->assertSame([], $mailboxClient->fetchedUids);
    }

    #[Test]
    #[DataProvider('connectivityFailures')]
    public function it_reports_a_safe_connectivity_failure_without_credentials_or_server_details(
        Throwable $failure,
        MailboxIntakeErrorCode $expectedCode,
        bool $enabled,
        int $expectedProbeCalls,
        int $expectedCloseCalls,
    ): void {
        $workspace = Workspace::factory()->create();
        $mailboxClient = (new FakeMailboxClient)->failProbeWith($failure);
        $this->configureMailbox($workspace->id);
        config()->set('opportunity_mailbox.enabled', $enabled);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $expectedCode = $enabled ? $expectedCode : MailboxIntakeErrorCode::ConfigurationInvalid;

        $this->artisan('opportunity:mailbox-check')
            ->expectsOutputToContain('status: failed')
            ->expectsOutputToContain('error_code: '.$expectedCode->value)
            ->doesntExpectOutputToContain('mailbox.invalid')
            ->doesntExpectOutputToContain('fixture-user')
            ->doesntExpectOutputToContain('fixture-password')
            ->assertExitCode(1);

        $this->assertSame($expectedProbeCalls, $mailboxClient->probeCallCount);
        $this->assertSame($expectedCloseCalls, $mailboxClient->closeCallCount);
        $this->assertSame([], $mailboxClient->discoveryCursors);
        $this->assertSame([], $mailboxClient->fetchedUids);
    }

    /** @return iterable<string, array{Throwable, MailboxIntakeErrorCode, bool, int, int}> */
    public static function connectivityFailures(): iterable
    {
        yield 'invalid configuration' => [
            new MailboxIntakeException(MailboxIntakeErrorCode::ConfigurationInvalid),
            MailboxIntakeErrorCode::ConfigurationInvalid,
            false,
            0,
            0,
        ];
        yield 'authentication failure' => [
            new MailboxIntakeException(MailboxIntakeErrorCode::AuthenticationFailed),
            MailboxIntakeErrorCode::AuthenticationFailed,
            true,
            1,
            1,
        ];
        yield 'connection failure' => [
            new MailboxIntakeException(MailboxIntakeErrorCode::ConnectionFailed),
            MailboxIntakeErrorCode::ConnectionFailed,
            true,
            1,
            1,
        ];
        yield 'folder unavailable' => [
            new MailboxIntakeException(MailboxIntakeErrorCode::FolderUnavailable),
            MailboxIntakeErrorCode::FolderUnavailable,
            true,
            1,
            1,
        ];
        yield 'unexpected private exception' => [
            new RuntimeException('fixture-password at mailbox.invalid for owner@example.test'),
            MailboxIntakeErrorCode::ConnectionFailed,
            true,
            1,
            1,
        ];
    }

    #[Test]
    #[DataProvider('pollOutcomes')]
    public function it_prints_only_safe_poll_counters_and_uses_documented_exit_codes(
        string $scenario,
        string $expectedStatus,
        int $expectedExitCode,
        int $expectedDiscoveredCount,
        int $expectedProcessedCount,
        int $expectedQuarantinedCount,
    ): void {
        $workspace = Workspace::factory()->create();
        $mailboxClient = new FakeMailboxClient;
        $arguments = [];
        $lock = null;
        $this->configureMailbox($workspace->id);

        if ($scenario === 'succeeded') {
            $mailboxClient->queueDiscovery(new DiscoveredMailboxBatch(9001, [], 0));
        } elseif ($scenario === 'partial') {
            $rawEmail = $this->unsupportedFixture();
            $mailboxClient->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 501, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 501,
            ))->withRawMessage(501, $rawEmail);
        } elseif ($scenario === 'failed') {
            $arguments['--workspace'] = '01J00000000000000000000000';
        } else {
            $lock = Cache::lock('opportunity-mailbox:poll:'.$workspace->id.':primary', 600);
            $this->assertTrue($lock->get());
            $this->assertSame(
                MailboxRunStatus::SkippedOverlap,
                $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id)->status,
            );
        }

        $this->app->instance(MailboxClient::class, $mailboxClient);

        try {
            $exitCode = Artisan::call('opportunity:poll-mailbox', $arguments);
            $output = Artisan::output();
        } finally {
            $lock?->release();
        }

        foreach ([
            'status: '.$expectedStatus,
            'discovered_count: '.$expectedDiscoveredCount,
            'processed_count: '.$expectedProcessedCount,
            'imported_count: 0',
            'updated_count: 0',
            'duplicate_count: 0',
            'quarantined_count: '.$expectedQuarantinedCount,
            'retry_scheduled_count: 0',
            'permanent_failure_count: 0',
        ] as $safeLine) {
            $this->assertStringContainsString($safeLine, $output);
        }

        foreach (['mailbox.invalid', 'fixture-user', 'fixture-password', 'owner@example.test', 'tracking-token'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }

        $this->assertSame($expectedExitCode, $exitCode, $output);
    }

    /** @return iterable<string, array{string, string, int, int, int, int}> */
    public static function pollOutcomes(): iterable
    {
        yield 'succeeded' => ['succeeded', 'succeeded', 0, 0, 0, 0];
        yield 'skipped overlap' => ['skipped_overlap', 'skipped_overlap', 0, 0, 0, 0];
        yield 'partial' => ['partial', 'partial', 2, 1, 1, 1];
        yield 'failed' => ['failed', 'failed', 1, 0, 0, 0];
    }

    #[Test]
    #[DataProvider('healthStates')]
    public function it_reports_healthy_degraded_unhealthy_and_never_run_states_from_persisted_data(
        string $scenario,
        string $expectedStatus,
        int $expectedExitCode,
    ): void {
        $this->travelTo('2026-08-30 12:00:00');
        $workspace = Workspace::factory()->create();
        $mailboxClient = new FakeMailboxClient;
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        if ($scenario === 'healthy') {
            $this->createRun($workspace, MailboxRunStatus::Succeeded, now()->subMinute());
        } elseif ($scenario === 'partial') {
            $this->createRun($workspace, MailboxRunStatus::Partial, now()->subMinute());
        } elseif ($scenario === 'quarantined') {
            $this->createRun($workspace, MailboxRunStatus::Succeeded, now()->subMinute());
            $this->createMessage($workspace, MailboxMessageStatus::Quarantined, 'email.missing_plain_text');
        } elseif ($scenario === 'future retry') {
            $this->createRun($workspace, MailboxRunStatus::Succeeded, now()->subMinute());
            $this->createMessage(
                $workspace,
                MailboxMessageStatus::RetryWait,
                MailboxIntakeErrorCode::MessageFetchFailed->value,
                now()->addMinute(),
            );
        } elseif ($scenario === 'failed') {
            $this->createRun($workspace, MailboxRunStatus::Failed, now()->subMinute(), [
                'error_code' => MailboxIntakeErrorCode::ConnectionFailed->value,
            ]);
        } elseif ($scenario === 'stale') {
            $this->createRun($workspace, MailboxRunStatus::Succeeded, now()->subMinutes(16));
        } elseif ($scenario === 'overdue retry') {
            $this->createRun($workspace, MailboxRunStatus::Succeeded, now()->subMinute());
            $this->createMessage(
                $workspace,
                MailboxMessageStatus::RetryWait,
                MailboxIntakeErrorCode::MessageFetchFailed->value,
                now()->subSecond(),
            );
        } elseif ($scenario === 'permanent failure') {
            $this->createRun($workspace, MailboxRunStatus::Succeeded, now()->subMinute());
            $this->createMessage(
                $workspace,
                MailboxMessageStatus::PermanentlyFailed,
                MailboxIntakeErrorCode::RetryExhausted->value,
            );
        } elseif ($scenario === 'invalid configuration') {
            config()->set('opportunity_mailbox.enabled', false);
        }

        $exitCode = Artisan::call('opportunity:mailbox-health');
        $output = Artisan::output();

        $this->assertSame($expectedExitCode, $exitCode, $output);
        $this->assertStringContainsString('status: '.$expectedStatus, $output);

        foreach (['mailbox.invalid', 'fixture-user', 'fixture-password', 'owner@example.test'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $output);
        }

        $this->assertSame(0, $mailboxClient->probeCallCount);
        $this->assertSame([], $mailboxClient->discoveryCursors);
        $this->assertSame([], $mailboxClient->fetchedUids);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function healthStates(): iterable
    {
        yield 'never run' => ['never run', 'never_run', 1];
        yield 'healthy' => ['healthy', 'healthy', 0];
        yield 'recent partial run' => ['partial', 'degraded', 1];
        yield 'quarantined work' => ['quarantined', 'degraded', 1];
        yield 'future retry' => ['future retry', 'degraded', 1];
        yield 'failed run' => ['failed', 'unhealthy', 1];
        yield 'stale run' => ['stale', 'unhealthy', 1];
        yield 'overdue retry' => ['overdue retry', 'unhealthy', 1];
        yield 'permanent failure' => ['permanent failure', 'unhealthy', 1];
        yield 'invalid configuration' => ['invalid configuration', 'unhealthy', 1];
    }

    #[Test]
    public function it_emits_safe_machine_readable_health_json(): void
    {
        $this->travelTo('2026-08-30 12:00:00');
        $workspace = Workspace::factory()->create();
        $mailboxClient = new FakeMailboxClient;
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $this->createRun($workspace, MailboxRunStatus::Partial, now()->subMinute(), [
            'discovered_count' => 4,
            'processed_count' => 3,
            'imported_count' => 1,
            'updated_count' => 1,
            'duplicate_count' => 0,
            'quarantined_count' => 1,
            'retry_scheduled_count' => 0,
            'permanent_failure_count' => 0,
            'error_code' => MailboxIntakeErrorCode::UidValidityChanged->value,
        ]);

        $exitCode = Artisan::call('opportunity:mailbox-health', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame([
            'status' => 'degraded',
            'last_run_status' => 'partial',
            'last_run_finished_at' => '2026-08-30T11:59:00+00:00',
            'discovered_count' => 4,
            'processed_count' => 3,
            'imported_count' => 1,
            'updated_count' => 1,
            'duplicate_count' => 0,
            'quarantined_count' => 1,
            'retry_scheduled_count' => 0,
            'permanent_failure_count' => 0,
            'error_code' => MailboxIntakeErrorCode::UidValidityChanged->value,
        ], $payload);
        $this->assertSame(0, $mailboxClient->probeCallCount);
        $this->assertSame([], $mailboxClient->discoveryCursors);
        $this->assertSame([], $mailboxClient->fetchedUids);
    }

    /** @param array<string, int|string|null> $overrides */
    private function createRun(
        Workspace $workspace,
        MailboxRunStatus $status,
        mixed $finishedAt,
        array $overrides = [],
    ): MailboxRun {
        return MailboxRun::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'status' => $status,
            'started_at' => $finishedAt,
            'finished_at' => $finishedAt,
            'discovered_count' => 0,
            'processed_count' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'duplicate_count' => 0,
            'quarantined_count' => 0,
            'retry_scheduled_count' => 0,
            'permanent_failure_count' => 0,
            'error_code' => null,
            ...$overrides,
        ]);
    }

    private function createMessage(
        Workspace $workspace,
        MailboxMessageStatus $status,
        string $errorCode,
        mixed $nextAttemptAt = null,
    ): MailboxMessage {
        return MailboxMessage::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'message_uid' => 601,
            'status' => $status,
            'attempt_count' => 1,
            'next_attempt_at' => $nextAttemptAt,
            'error_code' => $errorCode,
            'first_seen_at' => now()->subMinutes(2),
            'processed_at' => $status === MailboxMessageStatus::RetryWait ? null : now()->subMinute(),
        ]);
    }

    private function configureMailbox(string $workspaceId): void
    {
        config()->set('opportunity_mailbox', [
            'enabled' => true,
            'workspace_id' => $workspaceId,
            'mailbox_key' => 'primary',
            'host' => 'mailbox.invalid',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'folder' => 'Job Alerts',
            'candidate_from' => 'upwork@t.upwork.com',
            'candidate_subject_prefix' => 'New job alert:',
            'batch_size' => 25,
            'initial_lookback_hours' => 24,
            'max_attempts' => 3,
            'health_max_age_minutes' => 15,
        ]);
    }

    private function unsupportedFixture(): string
    {
        return str_replace(
            ['<fixture-hourly-client-success-1@example.test>', 'Content-Type: text/plain; charset=UTF-8'],
            ['<fixture-command-unsupported@example.test>', 'Content-Type: application/octet-stream'],
            $this->fixture('hourly-client-success.eml'),
        );
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Emails/upwork/'.$name));

        $this->assertIsString($contents);

        return $contents;
    }
}
