<?php

namespace App\Application\Mailbox;

use App\Application\Mailbox\Data\MailboxRunResult;
use App\Application\Opportunities\ImportOpportunityEmail;
use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Data\MailboxCursor;
use App\Domain\Mailbox\Data\MailboxMessageReference;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Enums\MailboxMessageStatus;
use App\Domain\Mailbox\Enums\MailboxRunStatus;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Domain\Opportunities\Enums\EmailImportStatus;
use App\Models\EmailImport;
use App\Models\MailboxCheckpoint;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PollOpportunityMailbox
{
    private const MAXIMUM_MESSAGE_BYTES = 1_048_576;

    private const LOCK_SECONDS = 600;

    public function __construct(
        private readonly Application $application,
        private readonly ImportOpportunityEmail $importOpportunityEmail,
    ) {}

    public function execute(string $workspaceId): MailboxRunResult
    {
        try {
            $configuration = $this->mailboxConfiguration();
        } catch (MailboxIntakeException $exception) {
            return $this->emptyResult(MailboxRunStatus::Failed, $exception->errorCode);
        }

        if (! $configuration->enabled
            || $configuration->workspaceId !== $workspaceId
            || ! Workspace::query()->whereKey($workspaceId)->exists()) {
            return $this->emptyResult(MailboxRunStatus::Failed, MailboxIntakeErrorCode::ConfigurationInvalid);
        }

        $lock = Cache::lock(
            'opportunity-mailbox:poll:'.$workspaceId.':'.$configuration->mailboxKey,
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            return $this->emptyResult(MailboxRunStatus::SkippedOverlap);
        }

        $client = null;
        $run = null;

        try {
            $run = MailboxRun::query()->create([
                'workspace_id' => $workspaceId,
                'mailbox_key' => $configuration->mailboxKey,
                'status' => MailboxRunStatus::Running,
                'started_at' => now(),
                'discovered_count' => 0,
                'processed_count' => 0,
                'imported_count' => 0,
                'updated_count' => 0,
                'duplicate_count' => 0,
                'quarantined_count' => 0,
                'retry_scheduled_count' => 0,
                'permanent_failure_count' => 0,
            ]);
            $checkpoint = MailboxCheckpoint::query()->firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'mailbox_key' => $configuration->mailboxKey,
                ],
                [
                    'uid_validity' => null,
                    'last_discovered_uid' => 0,
                ],
            );
            $cursor = new MailboxCursor(
                uidValidity: $checkpoint->uid_validity,
                lastDiscoveredUid: $checkpoint->last_discovered_uid,
                initialLookbackAt: $checkpoint->uid_validity === null || $checkpoint->uid_validity < 1
                    ? CarbonImmutable::now('UTC')->subHours($configuration->initialLookbackHours)
                    : null,
            );
            $client = $this->application->make(MailboxClient::class);
            $batch = $client->discover($cursor, $configuration->batchSize);
            $uidValidityChanged = $checkpoint->uid_validity !== null
                && $checkpoint->uid_validity !== $batch->uidValidity;

            DB::transaction(function () use ($batch, $checkpoint, $configuration, $workspaceId): void {
                $now = now();

                foreach ($batch->messages as $message) {
                    MailboxMessage::query()->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'workspace_id' => $workspaceId,
                        'opportunity_id' => null,
                        'mailbox_key' => $configuration->mailboxKey,
                        'uid_validity' => $batch->uidValidity,
                        'message_uid' => $message->uid,
                        'status' => MailboxMessageStatus::Pending->value,
                        'attempt_count' => 0,
                        'next_attempt_at' => null,
                        'error_code' => null,
                        'first_seen_at' => $now,
                        'processed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $recordedHighestUid = MailboxMessage::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('mailbox_key', $configuration->mailboxKey)
                    ->where('uid_validity', $batch->uidValidity)
                    ->whereIn('message_uid', array_map(
                        static fn ($message): int => $message->uid,
                        $batch->messages,
                    ))
                    ->max('message_uid');

                $checkpoint->update([
                    'uid_validity' => $batch->uidValidity,
                    'last_discovered_uid' => $recordedHighestUid === null
                        ? ($checkpoint->uid_validity === $batch->uidValidity ? $checkpoint->last_discovered_uid : 0)
                        : (int) $recordedHighestUid,
                ]);
            });

            $counters = [
                'discovered_count' => count($batch->messages),
                'processed_count' => 0,
                'imported_count' => 0,
                'updated_count' => 0,
                'duplicate_count' => 0,
                'quarantined_count' => 0,
                'retry_scheduled_count' => 0,
                'permanent_failure_count' => 0,
            ];

            $references = [];
            foreach ($batch->messages as $reference) {
                $references[$batch->uidValidity.':'.$reference->uid] = $reference;
            }

            $dueMessages = MailboxMessage::query()
                ->where('workspace_id', $workspaceId)
                ->where('mailbox_key', $configuration->mailboxKey)
                ->where('uid_validity', $batch->uidValidity)
                ->where(function ($query): void {
                    $query->where('status', MailboxMessageStatus::Pending)
                        ->orWhere(function ($query): void {
                            $query->where('status', MailboxMessageStatus::RetryWait)
                                ->where('next_attempt_at', '<=', now());
                        });
                })
                ->orderBy('message_uid')
                ->orderBy('uid_validity')
                ->limit($configuration->batchSize)
                ->get();

            foreach ($dueMessages as $message) {
                $reference = $references[$message->uid_validity.':'.$message->message_uid]
                    ?? new MailboxMessageReference($message->message_uid, 0);
                $outcome = $this->processMessage(
                    $message,
                    $reference,
                    $client,
                    $configuration,
                    $workspaceId,
                );
                $counters['processed_count']++;
                $counter = match ($outcome) {
                    MailboxMessageStatus::RetryWait => 'retry_scheduled_count',
                    MailboxMessageStatus::PermanentlyFailed => 'permanent_failure_count',
                    default => $outcome->value.'_count',
                };
                $counters[$counter]++;
            }

            $status = $uidValidityChanged
                || $counters['quarantined_count'] > 0
                || $counters['retry_scheduled_count'] > 0
                || $counters['permanent_failure_count'] > 0
                ? MailboxRunStatus::Partial
                : MailboxRunStatus::Succeeded;
            $run->update([
                ...$counters,
                'status' => $status,
                'finished_at' => now(),
                'error_code' => $uidValidityChanged
                    ? MailboxIntakeErrorCode::UidValidityChanged->value
                    : null,
            ]);

            return $this->resultFromRun($run);
        } catch (MailboxIntakeException $exception) {
            return $this->failRun($run, $exception->errorCode);
        } catch (Throwable) {
            return $this->failRun($run, MailboxIntakeErrorCode::ImportFailed);
        } finally {
            try {
                $client?->close();
            } catch (Throwable) {
            }

            $this->releaseLock($lock);
        }
    }

    private function failRun(?MailboxRun $run, MailboxIntakeErrorCode $errorCode): MailboxRunResult
    {
        if ($run === null) {
            return $this->emptyResult(MailboxRunStatus::Failed, $errorCode);
        }

        $run->update([
            'status' => MailboxRunStatus::Failed,
            'finished_at' => now(),
            'error_code' => $errorCode->value,
        ]);

        return $this->resultFromRun($run);
    }

    private function resultFromRun(MailboxRun $run): MailboxRunResult
    {
        return new MailboxRunResult(
            status: MailboxRunStatus::from((string) $run->getRawOriginal('status')),
            discoveredCount: $run->discovered_count ?? 0,
            processedCount: $run->processed_count ?? 0,
            importedCount: $run->imported_count ?? 0,
            updatedCount: $run->updated_count ?? 0,
            duplicateCount: $run->duplicate_count ?? 0,
            quarantinedCount: $run->quarantined_count ?? 0,
            retryScheduledCount: $run->retry_scheduled_count ?? 0,
            permanentFailureCount: $run->permanent_failure_count ?? 0,
            errorCode: $run->error_code === null ? null : MailboxIntakeErrorCode::from($run->error_code),
        );
    }

    private function emptyResult(
        MailboxRunStatus $status,
        ?MailboxIntakeErrorCode $errorCode = null,
    ): MailboxRunResult {
        return new MailboxRunResult(
            status: $status,
            discoveredCount: 0,
            processedCount: 0,
            importedCount: 0,
            updatedCount: 0,
            duplicateCount: 0,
            quarantinedCount: 0,
            retryScheduledCount: 0,
            permanentFailureCount: 0,
            errorCode: $errorCode,
        );
    }

    private function releaseLock(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
        }
    }

    private function processMessage(
        MailboxMessage $message,
        MailboxMessageReference $reference,
        MailboxClient $client,
        MailboxConfiguration $configuration,
        string $workspaceId,
    ): MailboxMessageStatus {
        if ($reference->reportedSize > self::MAXIMUM_MESSAGE_BYTES) {
            return $this->quarantineMessage($message, MailboxIntakeErrorCode::MessageTooLarge->value);
        }

        try {
            $rawEmail = $client->fetchRaw($reference, self::MAXIMUM_MESSAGE_BYTES);

            if (strlen($rawEmail) > self::MAXIMUM_MESSAGE_BYTES) {
                unset($rawEmail);

                return $this->quarantineMessage($message, MailboxIntakeErrorCode::MessageTooLarge->value);
            }

            $contentHash = hash('sha256', $rawEmail);

            try {
                $importResult = $this->importOpportunityEmail->execute($workspaceId, $rawEmail);
            } finally {
                unset($rawEmail);
            }

            $status = MailboxMessageStatus::from($importResult->status->value);
            $errorCode = $importResult->status === EmailImportStatus::Quarantined
                ? 'email.'.$importResult->errorCode?->value
                : null;

            if ($importResult->status === EmailImportStatus::Duplicate && $importResult->opportunityId === null) {
                $committedImport = EmailImport::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('content_sha256', $contentHash)
                    ->where('status', EmailImportStatus::Quarantined->value)
                    ->first();

                if ($committedImport !== null) {
                    $status = MailboxMessageStatus::Quarantined;
                    $errorCode = 'email.'.$committedImport->error_code;
                }
            }

            $message->update([
                'opportunity_id' => $importResult->opportunityId,
                'status' => $status,
                'attempt_count' => $message->attempt_count + 1,
                'next_attempt_at' => null,
                'error_code' => $errorCode,
                'processed_at' => now(),
            ]);

            return $status;
        } catch (MailboxIntakeException $exception) {
            $message->refresh();

            if ($exception->errorCode === MailboxIntakeErrorCode::MessageTooLarge) {
                return $this->quarantineMessage($message, $exception->errorCode->value);
            }

            return $this->deferOrFailMessage($message, $configuration, $exception->errorCode);
        } catch (Throwable) {
            $message->refresh();

            return $this->deferOrFailMessage($message, $configuration, MailboxIntakeErrorCode::ImportFailed);
        }
    }

    private function quarantineMessage(MailboxMessage $message, string $errorCode): MailboxMessageStatus
    {
        $message->update([
            'status' => MailboxMessageStatus::Quarantined,
            'attempt_count' => $message->attempt_count + 1,
            'next_attempt_at' => null,
            'error_code' => $errorCode,
            'processed_at' => now(),
        ]);

        return MailboxMessageStatus::Quarantined;
    }

    private function deferOrFailMessage(
        MailboxMessage $message,
        MailboxConfiguration $configuration,
        MailboxIntakeErrorCode $errorCode,
    ): MailboxMessageStatus {
        $attemptCount = $message->attempt_count + 1;

        if ($attemptCount >= $configuration->maxAttempts) {
            $message->update([
                'status' => MailboxMessageStatus::PermanentlyFailed,
                'attempt_count' => $attemptCount,
                'next_attempt_at' => null,
                'error_code' => MailboxIntakeErrorCode::RetryExhausted->value,
                'processed_at' => now(),
            ]);

            return MailboxMessageStatus::PermanentlyFailed;
        }

        $message->update([
            'status' => MailboxMessageStatus::RetryWait,
            'attempt_count' => $attemptCount,
            'next_attempt_at' => now()->addMinutes($attemptCount === 1 ? 5 : 15),
            'error_code' => $errorCode->value,
            'processed_at' => null,
        ]);

        return MailboxMessageStatus::RetryWait;
    }

    private function mailboxConfiguration(): MailboxConfiguration
    {
        return MailboxConfiguration::fromArray(
            (array) config('opportunity_mailbox'),
            isTesting: $this->application->environment('testing'),
        );
    }
}
