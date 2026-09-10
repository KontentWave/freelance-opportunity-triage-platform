<?php

namespace App\Console\Commands;

use App\Application\Triage\EvaluateOpportunity;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Models\OpportunityEvaluation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Throwable;

#[Signature('opportunity:triage {opportunity} {--workspace=} {--profile=} {--json}')]
#[Description('Evaluate one imported opportunity with a local scoring profile')]
final class TriageOpportunityCommand extends Command
{
    private const MAXIMUM_PROFILE_BYTES = 65_536;

    private const REMINDER = 'Review the full opportunity scope, verify credible delivery capability, and confirm the work fits within 20 hours/week before applying.';

    public function __construct(
        private readonly EvaluateOpportunity $evaluateOpportunity,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $profile = $this->loadProfile($this->option('profile'));
            $workspaceId = $this->requiredString($this->option('workspace'), TriageErrorCode::NotFound);
            $opportunityId = $this->requiredString($this->argument('opportunity'), TriageErrorCode::NotFound);
            $evaluation = $this->evaluateOpportunity->execute($workspaceId, $opportunityId, $profile);
        } catch (TriageException $exception) {
            return $this->outputError($exception->errorCode);
        } catch (Throwable) {
            return $this->outputError(TriageErrorCode::OperationFailed);
        }

        $this->outputEvaluation($evaluation);

        return self::SUCCESS;
    }

    private function loadProfile(mixed $profileOption): ScoringProfile
    {
        $path = $this->requiredString($profileOption, TriageErrorCode::ProfileInvalid);

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || ! is_file($path)
            || ! is_readable($path)) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        $size = filesize($path);

        if (! is_int($size) || $size > self::MAXIMUM_PROFILE_BYTES) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        $contents = file_get_contents($path, false, null, 0, self::MAXIMUM_PROFILE_BYTES + 1);

        if (! is_string($contents) || strlen($contents) > self::MAXIMUM_PROFILE_BYTES) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        try {
            $definition = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($definition)) {
                throw new InvalidArgumentException;
            }

            return ScoringProfile::fromArray($definition);
        } catch (JsonException|InvalidArgumentException) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }
    }

    private function requiredString(mixed $value, TriageErrorCode $errorCode): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new TriageException($errorCode);
        }

        return trim($value);
    }

    private function outputEvaluation(OpportunityEvaluation $evaluation): void
    {
        $payload = [
            'evaluation_id' => $evaluation->id,
            'opportunity_id' => $evaluation->opportunity_id,
            'engine_version' => $evaluation->engine_version,
            'profile_version' => $evaluation->profile_version,
            'input_sha256' => $evaluation->input_sha256,
            'recommendation' => $evaluation->recommendation->value,
            'score' => $evaluation->score,
            'result' => $evaluation->result,
            'reminder' => self::REMINDER,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        foreach (array_slice($payload, 0, 7) as $key => $value) {
            $this->line($key.': '.$value);
        }

        foreach ($evaluation->result['contributions'] as $contribution) {
            $this->line(sprintf(
                '%s: %s (%d/%d) - %s',
                $contribution['rule'],
                $contribution['state'],
                $contribution['points'],
                $contribution['maximum_points'],
                $contribution['explanation'],
            ));
        }

        $this->line('missing_fields: '.($evaluation->result['missing_fields'] === []
            ? 'none'
            : implode(', ', $evaluation->result['missing_fields'])));
        $this->line('hard_exclusions: '.($evaluation->result['hard_exclusions'] === []
            ? 'none'
            : implode(', ', $evaluation->result['hard_exclusions'])));
        $this->line('decision_reason_code: '.$evaluation->result['decision_reason_code']);
        $this->line('decision: '.$evaluation->result['decision_explanation']);
        $this->line('manual_review_required: true');
        $this->line('reminder_full_scope: Review the full opportunity scope.');
        $this->line('reminder_capability: Verify credible delivery capability.');
        $this->line('reminder_availability: Confirm the work fits within 20 hours/week before applying.');
    }

    private function outputError(TriageErrorCode $errorCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['error_code' => $errorCode->value]));
        } else {
            $this->line('error_code: '.$errorCode->value);
        }

        return self::FAILURE;
    }
}
