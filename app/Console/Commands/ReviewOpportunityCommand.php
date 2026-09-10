<?php

namespace App\Console\Commands;

use App\Application\Triage\RecordOpportunityReview;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Models\OpportunityReview;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('opportunity:review {evaluation} {label : APPLY, MAYBE, or SKIP} {--workspace=} {--reason= : fit, availability, economics, client_risk, missing_information, or other} {--sample-kind=demo} {--json}')]
#[Description('Record or explicitly revise the human judgment for one triage evaluation')]
final class ReviewOpportunityCommand extends Command
{
    private const REMINDER = 'Review the full opportunity scope, verify credible delivery capability, and confirm the work fits within 20 hours/week before applying.';

    public function __construct(
        private readonly RecordOpportunityReview $recordOpportunityReview,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $workspaceId = $this->requiredString($this->option('workspace'), TriageErrorCode::NotFound);
            $evaluationId = $this->requiredString($this->argument('evaluation'), TriageErrorCode::NotFound);
            $humanLabel = $this->requiredString($this->argument('label'), TriageErrorCode::ReviewInvalid);
            $sampleKind = $this->requiredString($this->option('sample-kind'), TriageErrorCode::ReviewInvalid);
            $reason = $this->option('reason');

            $review = $this->recordOpportunityReview->execute(
                $workspaceId,
                $evaluationId,
                $humanLabel,
                $reason,
                $sampleKind,
            );
        } catch (TriageException $exception) {
            return $this->outputError($exception->errorCode);
        } catch (Throwable) {
            return $this->outputError(TriageErrorCode::OperationFailed);
        }

        $this->outputReview($review);

        return self::SUCCESS;
    }

    private function requiredString(mixed $value, TriageErrorCode $errorCode): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new TriageException($errorCode);
        }

        return trim($value);
    }

    private function outputReview(OpportunityReview $review): void
    {
        $payload = [
            'review_id' => $review->id,
            'evaluation_id' => $review->evaluation_id,
            'human_label' => $review->human_label->value,
            'reason_code' => $review->reason_code,
            'sample_kind' => $review->sample_kind,
            'reminder' => self::REMINDER,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        foreach ($payload as $key => $value) {
            $this->line($key.': '.($value ?? 'none'));
        }
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
