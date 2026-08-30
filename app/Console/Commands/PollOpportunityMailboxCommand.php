<?php

namespace App\Console\Commands;

use App\Application\Mailbox\Data\MailboxRunResult;
use App\Application\Mailbox\PollOpportunityMailbox;
use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Enums\MailboxRunStatus;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

#[Signature('opportunity:poll-mailbox {--workspace=}')]
#[Description('Poll the configured opportunity mailbox for candidate alerts')]
final class PollOpportunityMailboxCommand extends Command
{
    public function __construct(
        private readonly Application $application,
        private readonly PollOpportunityMailbox $pollOpportunityMailbox,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $configuration = MailboxConfiguration::fromArray(
                (array) config('opportunity_mailbox'),
                isTesting: $this->application->environment('testing'),
            );
        } catch (MailboxIntakeException $exception) {
            return $this->outputResult($this->failedResult($exception->errorCode));
        }

        $workspaceOption = $this->option('workspace');
        $workspaceId = is_string($workspaceOption) && trim($workspaceOption) !== ''
            ? trim($workspaceOption)
            : $configuration->workspaceId;

        if ($workspaceId === null || ! Workspace::query()->whereKey($workspaceId)->exists()) {
            return $this->outputResult($this->failedResult(MailboxIntakeErrorCode::ConfigurationInvalid));
        }

        try {
            return $this->outputResult($this->pollOpportunityMailbox->execute($workspaceId));
        } catch (Throwable) {
            return $this->outputResult($this->failedResult(MailboxIntakeErrorCode::ImportFailed));
        }
    }

    private function outputResult(MailboxRunResult $result): int
    {
        $this->line('status: '.$result->status->value);
        $this->line('discovered_count: '.$result->discoveredCount);
        $this->line('processed_count: '.$result->processedCount);
        $this->line('imported_count: '.$result->importedCount);
        $this->line('updated_count: '.$result->updatedCount);
        $this->line('duplicate_count: '.$result->duplicateCount);
        $this->line('quarantined_count: '.$result->quarantinedCount);
        $this->line('retry_scheduled_count: '.$result->retryScheduledCount);
        $this->line('permanent_failure_count: '.$result->permanentFailureCount);

        if ($result->errorCode !== null) {
            $this->line('error_code: '.$result->errorCode->value);
        }

        return match ($result->status) {
            MailboxRunStatus::Succeeded, MailboxRunStatus::SkippedOverlap => self::SUCCESS,
            MailboxRunStatus::Partial => 2,
            MailboxRunStatus::Running, MailboxRunStatus::Failed => self::FAILURE,
        };
    }

    private function failedResult(MailboxIntakeErrorCode $errorCode): MailboxRunResult
    {
        return new MailboxRunResult(
            status: MailboxRunStatus::Failed,
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
}
