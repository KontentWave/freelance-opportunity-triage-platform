<?php

namespace App\Application\Review;

use App\Application\Triage\BuildOpportunityTriageInput;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\OpportunityScorer;
use App\Models\Opportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ListReviewOpportunities
{
    public function __construct(private readonly BuildOpportunityTriageInput $buildInput) {}

    /**
     * @param  array{recommendation: string, review: string, missing: string, page: int}  $filters
     * @return LengthAwarePaginator<int, Opportunity>
     */
    public function execute(string $workspaceId, ScoringProfile $profile, array $filters): LengthAwarePaginator
    {
        $query = $this->query($workspaceId, $profile);

        if ($filters['recommendation'] === 'UNSCORED') {
            $query->whereNull(DB::raw('COALESCE(pointer_evaluations.id, newest_evaluations.id)'));
        } elseif ($filters['recommendation'] !== 'ALL') {
            $query->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(current_enrichments.result, '$.recommendation')), pointer_evaluations.recommendation, newest_evaluations.recommendation) = ?",
                [$filters['recommendation']],
            );
        }

        if ($filters['review'] === 'reviewed') {
            $query->whereNotNull('current_reviews.id');
        } elseif ($filters['review'] === 'unreviewed') {
            $query->whereNull('current_reviews.id');
        }

        if ($filters['missing'] === 'present') {
            $query->whereNotNull(DB::raw('COALESCE(pointer_evaluations.id, newest_evaluations.id)'))
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(COALESCE(current_enrichments.result, pointer_evaluations.result, newest_evaluations.result), '$.missing_fields')) > 0");
        } elseif ($filters['missing'] === 'none') {
            $query->whereNotNull(DB::raw('COALESCE(pointer_evaluations.id, newest_evaluations.id)'))
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(COALESCE(current_enrichments.result, pointer_evaluations.result, newest_evaluations.result), '$.missing_fields')) = 0");
        }

        $query
            ->orderByRaw("CASE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(current_enrichments.result, '$.recommendation')), pointer_evaluations.recommendation, newest_evaluations.recommendation) WHEN 'APPLY' THEN 1 WHEN 'MAYBE' THEN 2 WHEN 'SKIP' THEN 3 ELSE 0 END")
            ->orderByDesc(DB::raw("COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(current_enrichments.result, '$.score')) AS UNSIGNED), pointer_evaluations.score, newest_evaluations.score)"))
            ->orderByRaw('opportunities.posted_on IS NULL')
            ->orderByDesc('opportunities.posted_on')
            ->orderBy('opportunities.id');

        /** @var LengthAwarePaginator<int, Opportunity> $paginator */
        $paginator = $query->paginate(25, page: $filters['page']);

        foreach ($paginator->items() as $opportunity) {
            $this->markStale($opportunity);
        }

        return $paginator;
    }

    /** @return Builder<Opportunity> */
    public function query(string $workspaceId, ScoringProfile $profile): Builder
    {
        return Opportunity::query()
            ->where('opportunities.workspace_id', $workspaceId)
            ->leftJoin('opportunity_evaluations as pointer_evaluations', function ($join) use ($workspaceId, $profile): void {
                $join->on('pointer_evaluations.id', '=', 'opportunities.review_evaluation_id')
                    ->on('pointer_evaluations.opportunity_id', '=', 'opportunities.id')
                    ->where('pointer_evaluations.workspace_id', $workspaceId)
                    ->where('pointer_evaluations.engine_version', OpportunityScorer::ENGINE_VERSION)
                    ->where('pointer_evaluations.profile_version', $profile->version);
            })
            ->leftJoin('opportunity_evaluations as newest_evaluations', function ($join) use ($workspaceId, $profile): void {
                $join->on('newest_evaluations.opportunity_id', '=', 'opportunities.id')
                    ->where('newest_evaluations.workspace_id', $workspaceId)
                    ->where('newest_evaluations.engine_version', OpportunityScorer::ENGINE_VERSION)
                    ->where('newest_evaluations.profile_version', $profile->version)
                    ->whereRaw('NOT EXISTS (SELECT 1 FROM opportunity_evaluations AS newer_evaluations WHERE newer_evaluations.workspace_id = newest_evaluations.workspace_id AND newer_evaluations.opportunity_id = newest_evaluations.opportunity_id AND newer_evaluations.engine_version = newest_evaluations.engine_version AND newer_evaluations.profile_version = newest_evaluations.profile_version AND (newer_evaluations.created_at > newest_evaluations.created_at OR (newer_evaluations.created_at = newest_evaluations.created_at AND newer_evaluations.id > newest_evaluations.id)))');
            })
            ->leftJoin('opportunity_enrichments as current_enrichments', function ($join): void {
                $join->whereRaw('current_enrichments.evaluation_id = COALESCE(pointer_evaluations.id, newest_evaluations.id)')
                    ->whereRaw('NOT EXISTS (SELECT 1 FROM opportunity_enrichments AS newer_enrichments WHERE newer_enrichments.evaluation_id = current_enrichments.evaluation_id AND newer_enrichments.revision > current_enrichments.revision)');
            })
            ->leftJoin('opportunity_reviews as current_reviews', function ($join): void {
                $join->whereRaw('current_reviews.evaluation_id = COALESCE(pointer_evaluations.id, newest_evaluations.id)')
                    ->whereRaw('current_reviews.enrichment_id <=> current_enrichments.id');
            })
            ->with('skills')
            ->select('opportunities.*')
            ->selectRaw('COALESCE(pointer_evaluations.id, newest_evaluations.id) AS displayed_evaluation_id')
            ->selectRaw('COALESCE(pointer_evaluations.profile_version, newest_evaluations.profile_version) AS displayed_profile_version')
            ->selectRaw('COALESCE(pointer_evaluations.profile_snapshot, newest_evaluations.profile_snapshot) AS displayed_profile_snapshot')
            ->selectRaw('COALESCE(pointer_evaluations.input_sha256, newest_evaluations.input_sha256) AS displayed_input_sha256')
            ->selectRaw('COALESCE(pointer_evaluations.input_snapshot, newest_evaluations.input_snapshot) AS displayed_input_snapshot')
            ->selectRaw('COALESCE(current_enrichments.result, pointer_evaluations.result, newest_evaluations.result) AS displayed_result')
            ->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(current_enrichments.result, '$.recommendation')), pointer_evaluations.recommendation, newest_evaluations.recommendation) AS displayed_recommendation")
            ->selectRaw("COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(current_enrichments.result, '$.score')) AS UNSIGNED), pointer_evaluations.score, newest_evaluations.score) AS displayed_score")
            ->selectRaw('COALESCE(pointer_evaluations.result, newest_evaluations.result) AS displayed_email_result')
            ->selectRaw('current_enrichments.id AS displayed_enrichment_id')
            ->selectRaw('current_enrichments.revision AS displayed_enrichment_revision')
            ->selectRaw('current_enrichments.full_description AS displayed_full_description')
            ->selectRaw('current_enrichments.overrides AS displayed_overrides')
            ->selectRaw('current_enrichments.input_snapshot AS displayed_enrichment_input')
            ->selectRaw('current_reviews.id AS current_review_id')
            ->selectRaw('current_reviews.enrichment_id AS current_review_enrichment_id')
            ->selectRaw('current_reviews.human_label AS current_review_human_label')
            ->selectRaw('current_reviews.reason_code AS current_review_reason_code')
            ->selectRaw('current_reviews.notes AS current_review_notes')
            ->selectRaw('current_reviews.outcome AS current_review_outcome')
            ->selectRaw('current_reviews.sample_kind AS current_review_sample_kind')
            ->withCasts([
                'displayed_profile_snapshot' => 'array',
                'displayed_input_snapshot' => 'array',
                'displayed_result' => 'array',
                'displayed_score' => 'integer',
                'displayed_email_result' => 'array',
                'displayed_enrichment_revision' => 'integer',
                'displayed_overrides' => 'array',
                'displayed_enrichment_input' => 'array',
            ]);
    }

    public function markStale(Opportunity $opportunity): Opportunity
    {
        $inputSha256 = $opportunity->getAttribute('displayed_input_sha256');
        $currentInput = $this->buildInput->execute($opportunity);
        $opportunity->setAttribute('displayed_current_input', $currentInput->snapshot);
        $opportunity->setAttribute(
            'displayed_stale',
            is_string($inputSha256) && $currentInput->sha256 !== $inputSha256,
        );

        return $opportunity;
    }
}
