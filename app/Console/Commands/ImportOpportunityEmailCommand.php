<?php

namespace App\Console\Commands;

use App\Application\Opportunities\ImportOpportunityEmail;
use App\Domain\Opportunities\Enums\EmailImportStatus;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('opportunity:import-email {path} {--workspace=}')]
#[Description('Import a local Upwork job-alert email into a workspace')]
class ImportOpportunityEmailCommand extends Command
{
    public function __construct(
        private readonly ImportOpportunityEmail $importOpportunityEmail,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $workspaceId = $this->option('workspace');

        if (! is_string($workspaceId) || trim($workspaceId) === '') {
            $this->error('The --workspace option is required.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('The provided path is not a readable file.');

            return self::FAILURE;
        }

        if (! Workspace::query()->whereKey($workspaceId)->exists()) {
            $this->error('The selected workspace does not exist.');

            return self::FAILURE;
        }

        $rawEmail = file_get_contents($path);

        if (! is_string($rawEmail)) {
            $this->error('The email file could not be read.');

            return self::FAILURE;
        }

        $result = $this->importOpportunityEmail->execute($workspaceId, $rawEmail);

        $this->line('status: '.$result->status->value);

        if ($result->opportunityId !== null) {
            $this->line('opportunity_id: '.$result->opportunityId);
        }

        if ($result->externalJobId !== null) {
            $this->line('external_job_id: '.$result->externalJobId);
        }

        if ($result->errorCode !== null) {
            $this->line('error_code: '.$result->errorCode->value);
        }

        if ($result->status === EmailImportStatus::Quarantined) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
