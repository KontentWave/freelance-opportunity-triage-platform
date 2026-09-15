<?php

namespace App\Http\Controllers;

use App\Application\Review\ListReviewOpportunities;
use App\Application\Review\ShowReviewOpportunity;
use App\Http\Resources\Review\OpportunityDetailResource;
use App\Http\Resources\Review\OpportunityListResource;
use App\Infrastructure\Triage\LocalScoringProfileLoader;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class OpportunityReviewController extends Controller
{
    public function index(): View
    {
        return view('review.index');
    }

    public function show(
        string $opportunity,
        ShowReviewOpportunity $showOpportunity,
        LocalScoringProfileLoader $profileLoader,
    ): View {
        $record = $showOpportunity->execute(
            (string) request()->user()->workspace_id,
            $opportunity,
            $profileLoader->load(config('opportunity_review.profile_path')),
        );
        Gate::authorize('view', $record);

        return view('review.show', ['opportunityId' => $record->id]);
    }

    public function list(
        Request $request,
        ListReviewOpportunities $listOpportunities,
        LocalScoringProfileLoader $profileLoader,
    ): JsonResponse {
        Gate::authorize('viewAny', Opportunity::class);
        $filters = $this->filters($request);
        $paginator = $listOpportunities->execute(
            (string) $request->user()->workspace_id,
            $profileLoader->load(config('opportunity_review.profile_path')),
            $filters,
        );

        return response()->json([
            'data' => OpportunityListResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function detail(
        Request $request,
        string $opportunity,
        ShowReviewOpportunity $showOpportunity,
        LocalScoringProfileLoader $profileLoader,
    ): JsonResponse {
        $record = $showOpportunity->execute(
            (string) $request->user()->workspace_id,
            $opportunity,
            $profileLoader->load(config('opportunity_review.profile_path')),
        );
        Gate::authorize('view', $record);

        return response()->json(['data' => (new OpportunityDetailResource($record))->resolve($request)]);
    }

    /** @return array{recommendation: string, review: string, missing: string, page: int} */
    private function filters(Request $request): array
    {
        $unknownKeys = array_diff(array_keys($request->query()), ['recommendation', 'review', 'missing', 'page']);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                (string) reset($unknownKeys) => 'This query parameter is not supported.',
            ]);
        }

        $validated = $request->validate([
            'recommendation' => ['sometimes', Rule::in(['ALL', 'UNSCORED', 'APPLY', 'MAYBE', 'SKIP'])],
            'review' => ['sometimes', Rule::in(['all', 'unreviewed', 'reviewed'])],
            'missing' => ['sometimes', Rule::in(['all', 'present', 'none'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return [
            'recommendation' => $validated['recommendation'] ?? 'ALL',
            'review' => $validated['review'] ?? 'all',
            'missing' => $validated['missing'] ?? 'all',
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }
}
