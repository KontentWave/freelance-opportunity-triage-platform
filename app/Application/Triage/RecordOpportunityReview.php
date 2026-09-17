<?php

namespace App\Application\Triage;

use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Enums\TriageRecommendation;
use App\Domain\Triage\Exceptions\TriageException;
use App\Domain\Triage\OpportunityScorer;
use App\Models\Opportunity;
use App\Models\OpportunityEnrichment;
use App\Models\OpportunityEvaluation;
use App\Models\OpportunityReview;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    private const OUTCOMES = [
        'not_applied',
        'applied',
        'in_discussion',
        'hired',
        'closed',
    ];

    public function __construct(private readonly BuildOpportunityTriageInput $buildInput) {}

    /**
     * @param  array{opportunity_id: string, enrichment_id: ?string, notes: ?string, outcome: ?string, profile: ScoringProfile}|null  $dashboardDetails
     */
    public function execute(
        string $workspaceId,
        string $evaluationId,
        string $humanLabel,
        ?string $reasonCode,
        string $sampleKind,
        ?array $dashboardDetails = null,
    ): OpportunityReview {
        return DB::transaction(function () use ($workspaceId, $evaluationId, $humanLabel, $reasonCode, $sampleKind, $dashboardDetails): OpportunityReview {
            $opportunity = $dashboardDetails === null
                ? null
                : Opportunity::query()
                    ->with('skills')
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($dashboardDetails['opportunity_id'])
                    ->lockForUpdate()
                    ->first();

            if ($dashboardDetails !== null && $opportunity === null) {
                throw (new ModelNotFoundException)->setModel(Opportunity::class, [$dashboardDetails['opportunity_id']]);
            }

            /** @var Opportunity|null $opportunity */
            $evaluation = OpportunityEvaluation::query()
                ->where('workspace_id', $workspaceId)
                ->when($opportunity !== null, fn ($query) => $query->where('opportunity_id', $opportunity->id))
                ->whereKey($evaluationId)
                ->lockForUpdate()
                ->first();

            if ($evaluation === null) {
                if ($dashboardDetails !== null) {
                    throw (new ModelNotFoundException)->setModel(OpportunityEvaluation::class, [$evaluationId]);
                }

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
                if ($dashboardDetails !== null) {
                    throw ValidationException::withMessages([
                        'reason_code' => 'Choose a reason when your label differs from the original email suggestion.',
                    ]);
                }

                throw new TriageException(TriageErrorCode::ReviewInvalid);
            }

            $enrichmentId = null;

            if ($dashboardDetails !== null) {
                $profile = $dashboardDetails['profile'];
                $enrichmentId = $dashboardDetails['enrichment_id'];
                $currentInput = $this->buildInput->execute($opportunity);

                if ($opportunity->review_evaluation_id !== $evaluation->id
                    || $evaluation->input_sha256 !== $currentInput->sha256
                    || $evaluation->engine_version !== OpportunityScorer::ENGINE_VERSION
                    || $evaluation->profile_version !== $profile->version) {
                    abort(409, 'The displayed evidence changed. Reload before saving.');
                }

                if ($sampleKind === 'real' && $profile->purpose !== 'personal') {
                    throw ValidationException::withMessages([
                        'sample_kind' => 'Real provenance requires the active personal profile.',
                    ]);
                }

                if ($enrichmentId !== null && ! OpportunityEnrichment::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('opportunity_id', $opportunity->id)
                    ->where('evaluation_id', $evaluation->id)
                    ->whereKey($enrichmentId)
                    ->exists()) {
                    throw (new ModelNotFoundException)->setModel(OpportunityEnrichment::class, [$enrichmentId]);
                }

                $latestEnrichmentId = OpportunityEnrichment::query()
                    ->where('evaluation_id', $evaluation->id)
                    ->orderByDesc('revision')
                    ->lockForUpdate()
                    ->value('id');

                if ($latestEnrichmentId !== $enrichmentId) {
                    abort(409, 'The displayed enrichment changed. Reload before saving.');
                }

                if ($dashboardDetails['outcome'] !== null
                    && ! in_array($dashboardDetails['outcome'], self::OUTCOMES, true)) {
                    throw ValidationException::withMessages([
                        'outcome' => 'The selected outcome is invalid.',
                    ]);
                }

                if (config('opportunity_review.mode') === 'demo') {
                    $sampleKind = 'demo';
                }
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

            if ($dashboardDetails !== null) {
                $attributes += [
                    'enrichment_id' => $enrichmentId,
                    'notes' => $dashboardDetails['notes'],
                    'outcome' => $dashboardDetails['outcome'],
                ];
            } elseif ($review !== null
                && ($review->human_label !== $label || $review->reason_code !== $reasonCode)) {
                $attributes['enrichment_id'] = null;
            }

            if ($review !== null) {
                if ($review->only(array_keys($attributes)) === collect($attributes)
                    ->map(fn (mixed $value): mixed => $value instanceof TriageRecommendation ? $value->value : $value)
                    ->all()) {
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
