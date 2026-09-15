<?php

namespace App\Application\Review;

use App\Application\Triage\EvaluateOpportunity;
use App\Domain\Triage\Data\ScoringProfile;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RefreshReviewEvaluation
{
    public function __construct(private readonly EvaluateOpportunity $evaluateOpportunity) {}

    public function execute(
        string $workspaceId,
        string $opportunityId,
        ScoringProfile $profile,
    ): OpportunityEvaluation {
        return DB::transaction(function () use ($workspaceId, $opportunityId, $profile): OpportunityEvaluation {
            $opportunity = Opportunity::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($opportunityId)
                ->lockForUpdate()
                ->first();

            if ($opportunity === null) {
                throw (new ModelNotFoundException)->setModel(Opportunity::class, [$opportunityId]);
            }

            $evaluation = $this->evaluateOpportunity->execute($workspaceId, $opportunityId, $profile);
            $opportunity->review_evaluation_id = $evaluation->id;
            $opportunity->save();

            return $evaluation;
        });
    }
}
