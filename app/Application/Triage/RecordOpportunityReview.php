<?php

namespace App\Application\Triage;

use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Enums\TriageRecommendation;
use App\Domain\Triage\Exceptions\TriageException;
use App\Models\OpportunityEvaluation;
use App\Models\OpportunityReview;
use Illuminate\Support\Facades\DB;

final class RecordOpportunityReview
{
    private const REASON_CODES = [
        'fit',
        'availability',
        'economics',
        'client_risk',
        'missing_information',
        'other',
    ];

    public function execute(
        string $workspaceId,
        string $evaluationId,
        string $humanLabel,
        ?string $reasonCode,
        string $sampleKind,
    ): OpportunityReview {
        return DB::transaction(function () use ($workspaceId, $evaluationId, $humanLabel, $reasonCode, $sampleKind): OpportunityReview {
            $evaluation = OpportunityEvaluation::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($evaluationId)
                ->lockForUpdate()
                ->first();

            if ($evaluation === null) {
                throw new TriageException(TriageErrorCode::NotFound);
            }

            $label = TriageRecommendation::tryFrom($humanLabel);
            $reasonCode = $reasonCode === null ? null : trim($reasonCode);

            if ($label === null
                || ! in_array($sampleKind, ['demo', 'real'], true)
                || ($reasonCode !== null && ! in_array($reasonCode, self::REASON_CODES, true))) {
                throw new TriageException(TriageErrorCode::ReviewInvalid);
            }

            if ($label !== $evaluation->recommendation && $reasonCode === null) {
                throw new TriageException(TriageErrorCode::ReviewInvalid);
            }

            $review = OpportunityReview::query()
                ->where('workspace_id', $workspaceId)
                ->where('evaluation_id', $evaluation->id)
                ->lockForUpdate()
                ->first();
            $attributes = [
                'human_label' => $label,
                'reason_code' => $reasonCode,
                'sample_kind' => $sampleKind,
            ];

            if ($review !== null) {
                if ($review->human_label === $label
                    && $review->reason_code === $reasonCode
                    && $review->sample_kind === $sampleKind) {
                    return $review;
                }

                $review->update($attributes + ['reviewed_at' => now()]);

                return $review;
            }

            return OpportunityReview::query()->create($attributes + [
                'workspace_id' => $workspaceId,
                'evaluation_id' => $evaluation->id,
                'reviewed_at' => now(),
            ]);
        });
    }
}
