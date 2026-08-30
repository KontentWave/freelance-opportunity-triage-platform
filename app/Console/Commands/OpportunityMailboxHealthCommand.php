<?php

namespace App\Console\Commands;

use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Enums\MailboxMessageStatus;
use App\Domain\Mailbox\Enums\MailboxRunStatus;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use App\Models\MailboxMessage;
use App\Models\MailboxRun;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

#[Signature('opportunity:mailbox-health {--workspace=} {--json}')]
#[Description('Report opportunity mailbox intake health from persisted state')]
final class OpportunityMailboxHealthCommand extends Command
{
    public function __construct(
        private readonly Application $application,
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
            return $this->outputHealth($this->emptyPayload('unhealthy', $exception->errorCode->value));
        }

        $workspaceOption = $this->option('workspace');
        $workspaceId = is_string($workspaceOption) && trim($workspaceOption) !== ''
            ? trim($workspaceOption)
            : $configuration->workspaceId;

        if (! $configuration->enabled
            || $workspaceId === null
            || $workspaceId !== $configuration->workspaceId
            || ! Workspace::query()->whereKey($workspaceId)->exists()) {
            return $this->outputHealth($this->emptyPayload(
                'unhealthy',
                MailboxIntakeErrorCode::ConfigurationInvalid->value,
            ));
        }

        return $this->outputHealth($this->healthPayload($configuration, $workspaceId));
    }

    /** @return array<string, int|string|null> */
    private function healthPayload(MailboxConfiguration $configuration, string $workspaceId): array
    {
        $latestRun = MailboxRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('mailbox_key', $configuration->mailboxKey)
            ->whereNotNull('finished_at')
            ->whereIn('status', [
                MailboxRunStatus::Succeeded,
                MailboxRunStatus::Partial,
                MailboxRunStatus::Failed,
            ])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
        $permanentFailure = $this->message($workspaceId, $configuration, MailboxMessageStatus::PermanentlyFailed);
        $overdueRetry = $this->message(
            $workspaceId,
            $configuration,
            MailboxMessageStatus::RetryWait,
            overdue: true,
        );

        if ($permanentFailure !== null) {
            return $this->payload($latestRun, 'unhealthy', $permanentFailure->error_code);
        }

        if ($overdueRetry !== null) {
            return $this->payload($latestRun, 'unhealthy', $overdueRetry->error_code);
        }

        if ($latestRun === null) {
            return $this->emptyPayload('never_run');
        }

        $latestRunStatus = MailboxRunStatus::from((string) $latestRun->getRawOriginal('status'));
        $finishedAt = CarbonImmutable::parse((string) $latestRun->getRawOriginal('finished_at'), 'UTC');

        if ($latestRunStatus === MailboxRunStatus::Failed
            || $finishedAt->lt(now('UTC')->subMinutes($configuration->healthMaxAgeMinutes))) {
            return $this->payload($latestRun, 'unhealthy', $latestRun->error_code);
        }

        $quarantined = $this->message($workspaceId, $configuration, MailboxMessageStatus::Quarantined);
        $pendingRetry = $this->message($workspaceId, $configuration, MailboxMessageStatus::RetryWait);

        if ($latestRunStatus === MailboxRunStatus::Partial || $quarantined !== null || $pendingRetry !== null) {
            $errorCode = $latestRun->error_code;

            if ($errorCode === null && $quarantined !== null) {
                $errorCode = $quarantined->error_code;
            }

            if ($errorCode === null && $pendingRetry !== null) {
                $errorCode = $pendingRetry->error_code;
            }

            return $this->payload($latestRun, 'degraded', $errorCode);
        }

        return $this->payload($latestRun, 'healthy');
    }

    private function message(
        string $workspaceId,
        MailboxConfiguration $configuration,
        MailboxMessageStatus $status,
        bool $overdue = false,
    ): ?MailboxMessage {
        return MailboxMessage::query()
            ->where('workspace_id', $workspaceId)
            ->where('mailbox_key', $configuration->mailboxKey)
            ->where('status', $status)
            ->when($overdue, fn ($query) => $query->where('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->first();
    }

    /** @return array<string, int|string|null> */
    private function payload(?MailboxRun $run, string $status, ?string $errorCode = null): array
    {
        if ($run === null) {
            return $this->emptyPayload($status, $errorCode);
        }

        return [
            'status' => $status,
            'last_run_status' => (string) $run->getRawOriginal('status'),
            'last_run_finished_at' => CarbonImmutable::parse(
                (string) $run->getRawOriginal('finished_at'),
                'UTC',
            )->toAtomString(),
            'discovered_count' => $run->discovered_count ?? 0,
            'processed_count' => $run->processed_count ?? 0,
            'imported_count' => $run->imported_count ?? 0,
            'updated_count' => $run->updated_count ?? 0,
            'duplicate_count' => $run->duplicate_count ?? 0,
            'quarantined_count' => $run->quarantined_count ?? 0,
            'retry_scheduled_count' => $run->retry_scheduled_count ?? 0,
            'permanent_failure_count' => $run->permanent_failure_count ?? 0,
            'error_code' => $this->safeErrorCode($errorCode),
        ];
    }

    /** @return array<string, int|string|null> */
    private function emptyPayload(string $status, ?string $errorCode = null): array
    {
        return [
            'status' => $status,
            'last_run_status' => null,
            'last_run_finished_at' => null,
            'discovered_count' => 0,
            'processed_count' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'duplicate_count' => 0,
            'quarantined_count' => 0,
            'retry_scheduled_count' => 0,
            'permanent_failure_count' => 0,
            'error_code' => $this->safeErrorCode($errorCode),
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function outputHealth(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($payload as $key => $value) {
                $this->line($key.': '.($value ?? 'none'));
            }
        }

        return $payload['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    private function safeErrorCode(?string $errorCode): ?string
    {
        if ($errorCode === null) {
            return null;
        }

        if (MailboxIntakeErrorCode::tryFrom($errorCode) !== null) {
            return $errorCode;
        }

        if (str_starts_with($errorCode, 'email.')
            && EmailParseErrorCode::tryFrom(substr($errorCode, 6)) !== null) {
            return $errorCode;
        }

        return null;
    }
}
