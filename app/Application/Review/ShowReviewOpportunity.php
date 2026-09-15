<?php

namespace App\Application\Review;

use App\Domain\Triage\Data\ScoringProfile;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ShowReviewOpportunity
{
    public function __construct(private readonly ListReviewOpportunities $listOpportunities) {}

    public function execute(string $workspaceId, string $opportunityId, ScoringProfile $profile): Opportunity
    {
        $opportunity = $this->listOpportunities->query($workspaceId, $profile)
            ->where('opportunities.id', $opportunityId)
            ->first();

        if ($opportunity === null) {
            throw (new ModelNotFoundException)->setModel(Opportunity::class, [$opportunityId]);
        }

        return $this->listOpportunities->markStale($opportunity);
    }
}
