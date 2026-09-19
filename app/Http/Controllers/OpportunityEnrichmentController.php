<?php

namespace App\Http\Controllers;

use App\Application\Review\DemoReviewConfiguration;
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
        DemoReviewConfiguration $demo,
    ): JsonResponse {
        $workspaceId = (string) $request->user()->workspace_id;
        $profile = $profileLoader->load(config('opportunity_review.profile_path'));
        $record = $showOpportunity->execute($workspaceId, $opportunity, $profile);
        Gate::authorize('update', $record);
        $validated = $request->validated();
        $preset = $demo->enabled() ? $demo->preset($validated['preset_key']) : null;

        $saveEnrichment->execute(
            $workspaceId,
            $opportunity,
            $validated['evaluation_id'],
            $validated['expected_enrichment_id'],
            $preset['full_description'] ?? $validated['full_description'],
            $preset['overrides'] ?? ($validated['overrides'] ?? []),
            $profile,
        );

        $refreshed = $showOpportunity->execute($workspaceId, $opportunity, $profile);

        return response()->json(['data' => (new OpportunityDetailResource($refreshed))->resolve($request)]);
    }
}
