<?php

namespace App\Console\Commands;

use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

#[Signature('opportunity:mailbox-check')]
#[Description('Check the configured opportunity mailbox connection')]
final class CheckOpportunityMailboxCommand extends Command
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
            return $this->reportFailure($exception->errorCode);
        }

        if (! $configuration->enabled
            || $configuration->workspaceId === null
            || ! Workspace::query()->whereKey($configuration->workspaceId)->exists()) {
            return $this->reportFailure(MailboxIntakeErrorCode::ConfigurationInvalid);
        }

        $client = null;

        try {
            $client = $this->application->make(MailboxClient::class);
            $probe = $client->probe();

            if (! $probe->successful) {
                return $this->reportFailure(MailboxIntakeErrorCode::ConnectionFailed);
            }

            $this->line('status: succeeded');

            return self::SUCCESS;
        } catch (MailboxIntakeException $exception) {
            return $this->reportFailure($exception->errorCode);
        } catch (Throwable) {
            return $this->reportFailure(MailboxIntakeErrorCode::ConnectionFailed);
        } finally {
            try {
                $client?->close();
            } catch (Throwable) {
            }
        }
    }

    private function reportFailure(MailboxIntakeErrorCode $errorCode): int
    {
        $this->line('status: failed');
        $this->line('error_code: '.$errorCode->value);

        return self::FAILURE;
    }
}
