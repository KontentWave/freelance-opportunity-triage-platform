<?php

namespace App\Http\Controllers;

use App\Application\Review\SaveOpportunityEnrichment;
use App\Application\Review\ShowReviewOpportunity;
use App\Http\Requests\Review\StoreOpportunityEnrichmentRequest;
use App\Http\Resources\Review\OpportunityDetailResource;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OpportunityEnrichmentController extends Controller
{
    public function store(
        StoreOpportunityEnrichmentRequest $request,
        string $opportunity,
        SaveOpportunityEnrichment $saveEnrichment,
        ShowReviewOpportunity $showOpportunity,
        LocalScoringProfileLoader $profileLoader,
    ): JsonResponse {
        $workspaceId = (string) $request->user()->workspace_id;
        $profile = $profileLoader->load(config('opportunity_review.profile_path'));
        $record = $showOpportunity->execute($workspaceId, $opportunity, $profile);
        Gate::authorize('update', $record);
        $validated = $request->validated();

        $saveEnrichment->execute(
            $workspaceId,
            $opportunity,
            $validated['evaluation_id'],
            $validated['expected_enrichment_id'],
            $validated['full_description'],
            $validated['overrides'] ?? [],
            $profile,
        );

        $refreshed = $showOpportunity->execute($workspaceId, $opportunity, $profile);

        return response()->json(['data' => (new OpportunityDetailResource($refreshed))->resolve($request)]);
    }
}
