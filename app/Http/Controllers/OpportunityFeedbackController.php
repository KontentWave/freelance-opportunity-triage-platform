<?php

namespace App\Http\Controllers;

use App\Application\Review\ShowReviewOpportunity;
use App\Application\Triage\RecordOpportunityReview;
use App\Http\Requests\Review\UpdateOpportunityFeedbackRequest;
use App\Http\Resources\Review\OpportunityDetailResource;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OpportunityFeedbackController extends Controller
{
    public function update(
        UpdateOpportunityFeedbackRequest $request,
        string $opportunity,
        ShowReviewOpportunity $showOpportunity,
        RecordOpportunityReview $recordReview,
        LocalScoringProfileLoader $profileLoader,
    ): JsonResponse {
        $workspaceId = (string) $request->user()->workspace_id;
        $profile = $profileLoader->load(config('opportunity_review.profile_path'));
        $record = $showOpportunity->execute($workspaceId, $opportunity, $profile);
        Gate::authorize('update', $record);
        $validated = $request->validated();

        $recordReview->execute(
            $workspaceId,
            $validated['evaluation_id'],
            $validated['human_label'],
            $validated['reason_code'],
            $validated['sample_kind'],
            [
                'opportunity_id' => $opportunity,
                'enrichment_id' => $validated['enrichment_id'],
                'notes' => $validated['notes'],
                'outcome' => $validated['outcome'],
                'profile' => $profile,
            ],
        );

        $refreshed = $showOpportunity->execute($workspaceId, $opportunity, $profile);

        return response()->json(['data' => (new OpportunityDetailResource($refreshed))->resolve($request)]);
    }
}
