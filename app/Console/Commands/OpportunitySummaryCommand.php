<?php

namespace App\Console\Commands;

use App\Application\Operations\BuildOperationalSummary;
use App\Domain\Triage\Exceptions\TriageException;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

#[Signature('opportunity:summary {--workspace=} {--json}')]
#[Description('Report workspace operational counts from persisted state')]
final class OpportunitySummaryCommand extends Command
{
    public function __construct(
        private readonly BuildOperationalSummary $buildSummary,
        private readonly LocalScoringProfileLoader $profileLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $workspaceId = $this->option('workspace');
            if (! is_string($workspaceId) || ! Str::isUlid($workspaceId)
                || ! Workspace::query()->whereKey($workspaceId)->exists()) {
                return $this->outputError('summary.invalid_workspace');
            }

            try {
                $profile = $this->profileLoader->load(config('opportunity_review.profile_path'));
            } catch (TriageException) {
                return $this->outputError('summary.profile_unavailable');
            }

            $summary = $this->buildSummary->execute($workspaceId, $profile, now('UTC')->toImmutable());
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($summary, JSON_THROW_ON_ERROR));
            } else {
                foreach ($summary as $key => $value) {
                    if (is_array($value)) {
                        foreach ($value as $field => $count) {
                            $this->line($key.'.'.$field.': '.(is_array($count) || is_object($count) ? json_encode($count, JSON_THROW_ON_ERROR) : ($count ?? 'none')));
                        }
                    } else {
                        $this->line($key.': '.$value);
                    }
                }
            }

            return self::SUCCESS;
        } catch (Throwable) {
            return $this->outputError('summary.unavailable');
        }
    }

    private function outputError(string $errorCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['schema_version' => 1, 'error_code' => $errorCode]));
        } else {
            $this->line('error_code: '.$errorCode);
        }

        return self::FAILURE;
    }
}
