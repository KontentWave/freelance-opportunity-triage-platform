<?php

namespace App\Application\Triage;

use App\Domain\Triage\CalibrationCalculator;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Models\OpportunityEvaluation;
use Illuminate\Support\Str;

final class BuildCalibrationReport
{
    public function __construct(
        private readonly CalibrationCalculator $calculator,
    ) {}

    /**
     * @param  list<string>  $evaluationIds
     * @return array<string, mixed>
     */
    public function execute(string $workspaceId, array $evaluationIds): array
    {
        if (count($evaluationIds) < 1
            || count($evaluationIds) > 100
            || count(array_unique($evaluationIds)) !== count($evaluationIds)) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        foreach ($evaluationIds as $evaluationId) {
            if (! Str::isUlid($evaluationId)) {
                throw new TriageException(TriageErrorCode::CohortInvalid);
            }
        }

        $evaluations = OpportunityEvaluation::query()
            ->with(['review' => fn ($query) => $query->where('workspace_id', $workspaceId)])
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $evaluationIds)
            ->get();

        if ($evaluations->count() !== count($evaluationIds)) {
            throw new TriageException(TriageErrorCode::NotFound);
        }

        if ($evaluations->pluck('engine_version')->unique()->count() !== 1
            || $evaluations->pluck('profile_version')->unique()->count() !== 1
            || $evaluations->pluck('opportunity_id')->unique()->count() !== $evaluations->count()) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        $profilePurpose = $evaluations->first()?->profile_snapshot['purpose'] ?? null;

        if (! in_array($profilePurpose, ['demo', 'personal'], true)) {
            throw new TriageException(TriageErrorCode::CohortInvalid);
        }

        $samples = $evaluations->map(static fn (OpportunityEvaluation $evaluation): array => [
            'machine_label' => $evaluation->recommendation,
            'human_label' => $evaluation->review?->human_label,
            'sample_kind' => $evaluation->review?->sample_kind,
            'missing_fields' => $evaluation->result['missing_fields'],
            'reason_code' => $evaluation->review?->reason_code,
        ])->all();

        return $this->calculator->calculate(
            $samples,
            $profilePurpose,
            (string) $evaluations->first()->engine_version,
            (string) $evaluations->first()->profile_version,
        );
    }
}
