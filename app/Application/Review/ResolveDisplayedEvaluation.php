<?php

namespace App\Application\Review;

use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\OpportunityScorer;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;

final class ResolveDisplayedEvaluation
{
    public function execute(Opportunity $opportunity, ScoringProfile $profile): ?OpportunityEvaluation
    {
        return OpportunityEvaluation::query()
            ->where('workspace_id', $opportunity->workspace_id)
            ->where('opportunity_id', $opportunity->id)
            ->where('engine_version', OpportunityScorer::ENGINE_VERSION)
            ->where('profile_version', $profile->version)
            ->orderByRaw('id = ? DESC', [$opportunity->review_evaluation_id])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }
}
