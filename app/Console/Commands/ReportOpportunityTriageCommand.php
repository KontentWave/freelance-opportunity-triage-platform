<?php

namespace App\Console\Commands;

use App\Application\Triage\BuildCalibrationReport;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

#[Signature('opportunity:triage-report {--workspace=} {--evaluations=} {--json}')]
#[Description('Build an aggregate calibration report for an explicit local evaluation cohort')]
final class ReportOpportunityTriageCommand extends Command
{
    private const MAXIMUM_COHORT_BYTES = 65_536;

    private const REMINDER = 'Review the full opportunity scope, verify credible delivery capability, and confirm the work fits within 20 hours/week before applying.';

    private const SCOPE = 'Scope: selected supported imports only; skip rate is a suggested reduction in listing opens, not marketplace-wide coverage or measured time savings.';

    public function __construct(
        private readonly BuildCalibrationReport $buildCalibrationReport,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $workspaceId = $this->requiredString($this->option('workspace'), TriageErrorCode::NotFound);
            $evaluationIds = $this->loadCohort($this->option('evaluations'));
            $report = $this->buildCalibrationReport->execute($workspaceId, $evaluationIds);
        } catch (TriageException $exception) {
            return $this->outputError($exception->errorCode);
        } catch (Throwable) {
            return $this->outputError(TriageErrorCode::OperationFailed);
        }

        $payload = $report + [
            'scope_statement' => self::SCOPE,
            'reminder' => self::REMINDER,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->line($key.': '.(is_array($value)
                ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->displayValue($key, $value)));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function loadCohort(mixed $cohortOption): array
    {
        $path = $this->requiredString($cohortOption, TriageErrorCode::CohortInvalid);

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || ! is_file($path)
            || ! is_readable($path)) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        $size = filesize($path);

        if (! is_int($size) || $size > self::MAXIMUM_COHORT_BYTES) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        $contents = file_get_contents($path, false, null, 0, self::MAXIMUM_COHORT_BYTES + 1);

        if (! is_string($contents) || strlen($contents) > self::MAXIMUM_COHORT_BYTES) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        try {
            $evaluationIds = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        if (! is_array($evaluationIds) || ! array_is_list($evaluationIds)) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        foreach ($evaluationIds as $evaluationId) {
            if (! is_string($evaluationId)) {
                throw new TriageException(TriageErrorCode::CohortInvalid);
            }
        }

        /** @var list<string> $evaluationIds */
        return $evaluationIds;
    }

    private function requiredString(mixed $value, TriageErrorCode $errorCode): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new TriageException($errorCode);
        }

        return trim($value);
    }

    private function displayValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (str_ends_with($key, '_percent') && is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function outputError(TriageErrorCode $errorCode): int
    {
        $payload = ['error_code' => $errorCode->value];
        $this->line((bool) $this->option('json')
            ? (string) json_encode($payload)
            : 'error_code: '.$errorCode->value);

        return self::FAILURE;
    }
}
