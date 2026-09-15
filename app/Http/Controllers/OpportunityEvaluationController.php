<?php

namespace App\Http\Controllers;

use App\Application\Review\RefreshReviewEvaluation;
use App\Application\Review\ShowReviewOpportunity;
use App\Http\Resources\Review\OpportunityDetailResource;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OpportunityEvaluationController extends Controller
{
    public function store(
        Request $request,
        string $opportunity,
        ShowReviewOpportunity $showOpportunity,
        RefreshReviewEvaluation $refreshEvaluation,
        LocalScoringProfileLoader $profileLoader,
    ): JsonResponse {
        if ($request->all() !== []) {
            return response()->json([
                'error_code' => 'review.invalid_input',
                'message' => 'The evaluation request contains unsupported input.',
                'errors' => ['request' => ['Evaluation inputs are selected by the server.']],
            ], 422);
        }

        $workspaceId = (string) $request->user()->workspace_id;
        $profile = $profileLoader->load(config('opportunity_review.profile_path'));
        $record = $showOpportunity->execute($workspaceId, $opportunity, $profile);
        Gate::authorize('update', $record);
        $refreshEvaluation->execute($workspaceId, $opportunity, $profile);
        $refreshed = $showOpportunity->execute($workspaceId, $opportunity, $profile);

        return response()->json(['data' => (new OpportunityDetailResource($refreshed))->resolve($request)]);
    }
}
