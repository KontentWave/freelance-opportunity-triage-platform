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
use App\Models\EmailImport;
use App\Models\MailboxCheckpoint;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Fakes\FakeMailboxClient;
use Tests\TestCase;

final class PollOpportunityMailboxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_a_new_candidate_alert_and_advances_its_checkpoint(): void
    {
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-client-success.eml');
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 101, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 101,
            ))
            ->withRawMessage(101, $rawEmail);
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Succeeded, $result->status);
        $this->assertSame(1, $result->discoveredCount);
        $this->assertSame(1, $result->processedCount);
        $this->assertSame(1, $result->importedCount);
        $this->assertNull($result->errorCode);

        $checkpoint = MailboxCheckpoint::query()->firstOrFail();
        $message = MailboxMessage::query()->firstOrFail();

        $this->assertSame($workspace->id, $checkpoint->workspace_id);
        $this->assertSame('primary', $checkpoint->mailbox_key);
        $this->assertSame(9001, $checkpoint->uid_validity);
        $this->assertSame(101, $checkpoint->last_discovered_uid);
        $this->assertSame(MailboxMessageStatus::Imported, $message->status);
        $this->assertSame(101, $message->message_uid);
        $this->assertNotNull($message->opportunity_id);
        $this->assertSame(1, Opportunity::query()->where('external_id', '200000000000000000001')->count());

        $this->assertCount(1, $mailboxClient->discoveryCursors);
        $this->assertNull($mailboxClient->discoveryCursors[0]->uidValidity);
        $this->assertSame(0, $mailboxClient->discoveryCursors[0]->lastDiscoveredUid);
        $this->assertNotNull($mailboxClient->discoveryCursors[0]->initialLookbackAt);
        $this->assertSame([25], $mailboxClient->discoveryLimits);
        $this->assertSame([101], $mailboxClient->fetchedUids);
        $this->assertSame(1, $mailboxClient->closeCallCount);
    }

    #[Test]
    public function it_records_discovery_before_processing_and_never_advances_past_an_unrecorded_uid(): void
    {
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-client-success.eml');
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 101, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 102,
            ))
            ->withRawMessage(101, $rawEmail)
            ->beforeFetch(function () use ($workspace): void {
                $this->assertDatabaseHas('mailbox_messages', [
                    'workspace_id' => $workspace->id,
                    'uid_validity' => 9001,
                    'message_uid' => 101,
                    'status' => MailboxMessageStatus::Pending->value,
                ]);
                $this->assertDatabaseHas('mailbox_checkpoints', [
                    'workspace_id' => $workspace->id,
                    'uid_validity' => 9001,
                    'last_discovered_uid' => 101,
                ]);
            });
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Succeeded, $result->status);
        $this->assertDatabaseMissing('mailbox_messages', [
            'workspace_id' => $workspace->id,
            'uid_validity' => 9001,
            'message_uid' => 102,
        ]);
        $this->assertSame(101, MailboxCheckpoint::query()->firstOrFail()->last_discovered_uid);
    }

    #[Test]
    public function it_skips_a_remote_uid_already_finalized_in_the_same_uidvalidity_namespace(): void
    {
        $workspace = Workspace::factory()->create();
        MailboxCheckpoint::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'last_discovered_uid' => 100,
        ]);
        MailboxMessage::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'message_uid' => 101,
            'status' => MailboxMessageStatus::Imported,
            'attempt_count' => 1,
            'first_seen_at' => now(),
            'processed_at' => now(),
        ]);
        $mailboxClient = (new FakeMailboxClient)->queueDiscovery(new DiscoveredMailboxBatch(
            uidValidity: 9001,
            messages: [new MailboxMessageReference(uid: 101, reportedSize: 1024)],
            highestDiscoveredUid: 101,
        ));
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Succeeded, $result->status);
        $this->assertSame(0, $result->processedCount);
        $this->assertSame([], $mailboxClient->fetchedUids);
        $this->assertSame(1, MailboxMessage::query()->count());
        $this->assertSame(101, MailboxCheckpoint::query()->firstOrFail()->last_discovered_uid);
    }

    #[Test]
    public function it_rescans_a_bounded_window_after_uidvalidity_changes_without_duplicate_opportunities(): void
    {
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-client-success.eml');
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 101, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 101,
            ))
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9002,
                messages: [new MailboxMessageReference(uid: 1, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 1,
            ))
            ->withRawMessage(101, $rawEmail)
            ->withRawMessage(1, $rawEmail);
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $action = $this->app->make(PollOpportunityMailbox::class);

        $firstResult = $action->execute($workspace->id);
        $secondResult = $action->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Succeeded, $firstResult->status);
        $this->assertSame(MailboxRunStatus::Partial, $secondResult->status);
        $this->assertSame(MailboxIntakeErrorCode::UidValidityChanged, $secondResult->errorCode);
        $this->assertSame(1, $secondResult->duplicateCount);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(2, MailboxMessage::query()->count());
        $this->assertSame(9001, $mailboxClient->discoveryCursors[1]->uidValidity);
        $this->assertSame(101, $mailboxClient->discoveryCursors[1]->lastDiscoveredUid);
        $this->assertSame(9002, MailboxCheckpoint::query()->firstOrFail()->uid_validity);
        $this->assertSame(1, MailboxCheckpoint::query()->firstOrFail()->last_discovered_uid);
    }

    #[Test]
    public function it_does_not_fetch_a_retry_from_an_invalidated_uidvalidity_namespace(): void
    {
        $this->travelTo('2026-08-30 12:00:00');
        $workspace = Workspace::factory()->create();
        MailboxCheckpoint::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'last_discovered_uid' => 102,
        ]);
        MailboxMessage::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'message_uid' => 102,
            'status' => MailboxMessageStatus::RetryWait,
            'attempt_count' => 1,
            'next_attempt_at' => now(),
            'error_code' => MailboxIntakeErrorCode::MessageFetchFailed->value,
            'first_seen_at' => now()->subMinutes(5),
        ]);
        $mailboxClient = (new FakeMailboxClient)->queueDiscovery(
            new DiscoveredMailboxBatch(9002, [], 0),
        );
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Partial, $result->status);
        $this->assertSame(MailboxIntakeErrorCode::UidValidityChanged, $result->errorCode);
        $this->assertSame(0, $result->processedCount);
        $this->assertSame([], $mailboxClient->fetchedUids);
        $this->assertDatabaseHas('mailbox_messages', [
            'workspace_id' => $workspace->id,
            'uid_validity' => 9001,
            'message_uid' => 102,
            'status' => MailboxMessageStatus::RetryWait->value,
            'attempt_count' => 1,
        ]);
    }

    #[Test]
    public function it_retries_a_temporary_fetch_failure_and_imports_exactly_once(): void
    {
        $this->travelTo('2026-08-30 12:00:00');
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-client-success.eml');
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 102, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 102,
            ))
            ->queueDiscovery(new DiscoveredMailboxBatch(9001, [], 102))
            ->withRawMessage(102, $rawEmail)
            ->failFetchTimes(102, 1);
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $action = $this->app->make(PollOpportunityMailbox::class);

        $firstResult = $action->execute($workspace->id);
        $message = MailboxMessage::query()->firstOrFail();

        $this->assertSame(MailboxRunStatus::Partial, $firstResult->status);
        $this->assertSame(1, $firstResult->retryScheduledCount);
        $this->assertSame(MailboxMessageStatus::RetryWait, $message->status);
        $this->assertSame(1, $message->attempt_count);
        $this->assertSame('2026-08-30 12:05:00', $message->getRawOriginal('next_attempt_at'));
        $this->assertSame(0, Opportunity::query()->count());

        $this->travel(5)->minutes();
        $secondResult = $action->execute($workspace->id);
        $message->refresh();

        $this->assertSame(MailboxRunStatus::Succeeded, $secondResult->status);
        $this->assertSame(MailboxMessageStatus::Imported, $message->status);
        $this->assertSame(2, $message->attempt_count);
        $this->assertNull($message->next_attempt_at);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(1, EmailImport::query()->count());
        $this->assertSame([102, 102], $mailboxClient->fetchedUids);
    }

    #[Test]
    public function it_reconciles_a_committed_quarantine_after_a_ledger_update_failure(): void
    {
        $this->travelTo('2026-08-30 12:00:00');
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->unsupportedFixture();
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 103, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 103,
            ))
            ->queueDiscovery(new DiscoveredMailboxBatch(9001, [], 103))
            ->withRawMessage(103, $rawEmail);
        $ledgerUpdateFailed = false;
        MailboxMessage::updating(function () use (&$ledgerUpdateFailed): void {
            if (! $ledgerUpdateFailed) {
                $ledgerUpdateFailed = true;

                throw new RuntimeException('Synthetic ledger update failure.');
            }
        });
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $action = $this->app->make(PollOpportunityMailbox::class);

        $firstResult = $action->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Partial, $firstResult->status);
        $this->assertSame(1, $firstResult->retryScheduledCount);
        $this->assertSame(1, EmailImport::query()->count());
        $this->assertSame('quarantined', EmailImport::query()->firstOrFail()->status);

        $this->travel(5)->minutes();
        $secondResult = $action->execute($workspace->id);
        $message = MailboxMessage::query()->firstOrFail();

        $this->assertSame(MailboxRunStatus::Partial, $secondResult->status);
        $this->assertSame(1, $secondResult->quarantinedCount);
        $this->assertSame(MailboxMessageStatus::Quarantined, $message->status);
        $this->assertSame('email.missing_plain_text', $message->error_code);
        $this->assertSame(1, EmailImport::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
    }

    #[Test]
    public function it_marks_a_message_permanently_failed_after_the_third_temporary_failure(): void
    {
        $this->travelTo('2026-08-30 12:00:00');
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-client-success.eml');
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 104, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 104,
            ))
            ->queueDiscovery(new DiscoveredMailboxBatch(9001, [], 104))
            ->queueDiscovery(new DiscoveredMailboxBatch(9001, [], 104))
            ->withRawMessage(104, $rawEmail)
            ->failFetchTimes(104, 3);
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $action = $this->app->make(PollOpportunityMailbox::class);

        $firstResult = $action->execute($workspace->id);
        $this->travel(5)->minutes();
        $secondResult = $action->execute($workspace->id);
        $this->travel(15)->minutes();
        $thirdResult = $action->execute($workspace->id);
        $message = MailboxMessage::query()->firstOrFail();

        $this->assertSame(MailboxRunStatus::Partial, $firstResult->status);
        $this->assertSame(MailboxRunStatus::Partial, $secondResult->status);
        $this->assertSame(MailboxRunStatus::Partial, $thirdResult->status);
        $this->assertSame(1, $thirdResult->permanentFailureCount);
        $this->assertSame(MailboxMessageStatus::PermanentlyFailed, $message->status);
        $this->assertSame(3, $message->attempt_count);
        $this->assertNull($message->next_attempt_at);
        $this->assertSame(MailboxIntakeErrorCode::RetryExhausted->value, $message->error_code);
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame([104, 104, 104], $mailboxClient->fetchedUids);
    }

    #[Test]
    public function it_quarantines_an_unsupported_candidate_and_continues_the_batch(): void
    {
        $workspace = Workspace::factory()->create();
        $unsupportedEmail = $this->unsupportedFixture();
        $supportedEmail = $this->fixture('hourly-client-success.eml');
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [
                    new MailboxMessageReference(uid: 105, reportedSize: strlen($unsupportedEmail)),
                    new MailboxMessageReference(uid: 106, reportedSize: strlen($supportedEmail)),
                ],
                highestDiscoveredUid: 106,
            ))
            ->withRawMessage(105, $unsupportedEmail)
            ->withRawMessage(106, $supportedEmail);
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Partial, $result->status);
        $this->assertSame(2, $result->processedCount);
        $this->assertSame(1, $result->quarantinedCount);
        $this->assertSame(1, $result->importedCount);
        $this->assertDatabaseHas('mailbox_messages', [
            'message_uid' => 105,
            'status' => MailboxMessageStatus::Quarantined->value,
            'error_code' => 'email.missing_plain_text',
        ]);
        $this->assertDatabaseHas('mailbox_messages', [
            'message_uid' => 106,
            'status' => MailboxMessageStatus::Imported->value,
        ]);
        $this->assertSame([105, 106], $mailboxClient->fetchedUids);
        $this->assertSame(106, MailboxCheckpoint::query()->firstOrFail()->last_discovered_uid);
        $this->assertSame(1, Opportunity::query()->count());
    }

    #[Test]
    public function it_does_not_advance_the_checkpoint_after_a_connection_level_failure(): void
    {
        $workspace = Workspace::factory()->create();
        MailboxCheckpoint::query()->create([
            'workspace_id' => $workspace->id,
            'mailbox_key' => 'primary',
            'uid_validity' => 9001,
            'last_discovered_uid' => 77,
        ]);
        $mailboxClient = (new FakeMailboxClient)->queueDiscoveryFailure(
            new MailboxIntakeException(MailboxIntakeErrorCode::ConnectionFailed),
        );
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Failed, $result->status);
        $this->assertSame(MailboxIntakeErrorCode::ConnectionFailed, $result->errorCode);
        $this->assertSame(9001, MailboxCheckpoint::query()->firstOrFail()->uid_validity);
        $this->assertSame(77, MailboxCheckpoint::query()->firstOrFail()->last_discovered_uid);
        $this->assertSame(0, MailboxMessage::query()->count());
        $this->assertSame(1, $mailboxClient->closeCallCount);
    }

    #[Test]
    public function it_prevents_overlapping_polls_for_the_same_workspace_and_mailbox(): void
    {
        $workspace = Workspace::factory()->create();
        $mailboxClient = new FakeMailboxClient;
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);
        $lock = Cache::lock('opportunity-mailbox:poll:'.$workspace->id.':primary', 600);
        $this->assertTrue($lock->get());

        try {
            $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);
        } finally {
            $lock->release();
        }

        $this->assertSame(MailboxRunStatus::SkippedOverlap, $result->status);
        $this->assertSame([], $mailboxClient->discoveryCursors);
        $this->assertSame([], $mailboxClient->fetchedUids);
        $this->assertSame(0, $mailboxClient->closeCallCount);
        $this->assertSame(0, MailboxRun::query()->count());
        $this->assertSame(0, MailboxMessage::query()->count());
    }

    #[Test]
    public function it_never_persists_raw_email_headers_bodies_recipients_or_credentials(): void
    {
        $workspace = Workspace::factory()->create();
        $validEmail = $this->fixture('hourly-client-success.eml');
        $oversizedEmail = str_repeat('x', 1_048_577);
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [
                    new MailboxMessageReference(uid: 107, reportedSize: 1_048_577),
                    new MailboxMessageReference(uid: 108, reportedSize: 1024),
                    new MailboxMessageReference(uid: 109, reportedSize: strlen($validEmail)),
                ],
                highestDiscoveredUid: 109,
            ))
            ->withRawMessage(108, $oversizedEmail)
            ->withRawMessage(109, $validEmail);
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Partial, $result->status);
        $this->assertSame([108, 109], $mailboxClient->fetchedUids);
        $this->assertDatabaseHas('mailbox_messages', [
            'message_uid' => 107,
            'status' => MailboxMessageStatus::Quarantined->value,
            'error_code' => MailboxIntakeErrorCode::MessageTooLarge->value,
        ]);
        $this->assertDatabaseHas('mailbox_messages', [
            'message_uid' => 108,
            'status' => MailboxMessageStatus::Quarantined->value,
            'error_code' => MailboxIntakeErrorCode::MessageTooLarge->value,
        ]);

        $deliveryState = json_encode([
            MailboxCheckpoint::query()->get()->map->getAttributes()->all(),
            MailboxMessage::query()->get()->map->getAttributes()->all(),
            MailboxRun::query()->get()->map->getAttributes()->all(),
        ], JSON_THROW_ON_ERROR);

        foreach (['owner@example.test', 'tracking-token', 'Message-ID:', 'fixture-password', 'mailbox.invalid'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $deliveryState);
        }
    }

    #[Test]
    public function it_never_logs_raw_exceptions_or_secrets(): void
    {
        $logger = Log::spy();
        $workspace = Workspace::factory()->create();
        $rawEmail = $this->fixture('hourly-client-success.eml');
        $privateFailure = 'Synthetic failure with fixture-password at mailbox.invalid for owner@example.test';
        $mailboxClient = (new FakeMailboxClient)
            ->queueDiscovery(new DiscoveredMailboxBatch(
                uidValidity: 9001,
                messages: [new MailboxMessageReference(uid: 110, reportedSize: strlen($rawEmail))],
                highestDiscoveredUid: 110,
            ))
            ->withRawMessage(110, $rawEmail)
            ->failFetchWith(110, new RuntimeException($privateFailure));
        $this->configureMailbox($workspace->id);
        $this->app->instance(MailboxClient::class, $mailboxClient);

        $result = $this->app->make(PollOpportunityMailbox::class)->execute($workspace->id);

        $this->assertSame(MailboxRunStatus::Partial, $result->status);
        $this->assertSame(1, $result->retryScheduledCount);
        $this->assertSame(MailboxIntakeErrorCode::ImportFailed->value, MailboxMessage::query()->firstOrFail()->error_code);
        $this->assertStringNotContainsString(
            $privateFailure,
            json_encode(MailboxMessage::query()->firstOrFail()->getAttributes(), JSON_THROW_ON_ERROR),
        );
        $logger->shouldNotHaveReceived('emergency');
        $logger->shouldNotHaveReceived('alert');
        $logger->shouldNotHaveReceived('critical');
        $logger->shouldNotHaveReceived('error');
        $logger->shouldNotHaveReceived('warning');
        $logger->shouldNotHaveReceived('notice');
        $logger->shouldNotHaveReceived('info');
        $logger->shouldNotHaveReceived('debug');
        $logger->shouldNotHaveReceived('log');
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

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Emails/upwork/'.$name));

        $this->assertIsString($contents);

        return $contents;
    }

    private function unsupportedFixture(): string
    {
        return str_replace(
            ['<fixture-hourly-client-success-1@example.test>', 'Content-Type: text/plain; charset=UTF-8'],
            ['<fixture-unsupported@example.test>', 'Content-Type: application/octet-stream'],
            $this->fixture('hourly-client-success.eml'),
        );
    }
}
