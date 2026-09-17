<?php

namespace App\Application\Review;

use App\Application\Triage\BuildOpportunityTriageInput;
use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Data\TriageInput;
use App\Domain\Triage\OpportunityScorer;
use App\Models\Opportunity;
use App\Models\OpportunityEnrichment;
use App\Models\OpportunityEvaluation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SaveOpportunityEnrichment
{
    public function __construct(
        private readonly BuildOpportunityTriageInput $buildInput,
        private readonly OpportunityScorer $scorer,
    ) {}

    /** @param array<string, mixed> $overrides */
    public function execute(
        string $workspaceId,
        string $opportunityId,
        string $evaluationId,
        ?string $expectedEnrichmentId,
        string $fullDescription,
        array $overrides,
        ScoringProfile $activeProfile,
    ): OpportunityEnrichment {
        return DB::transaction(function () use ($workspaceId, $opportunityId, $evaluationId, $expectedEnrichmentId, $fullDescription, $overrides, $activeProfile): OpportunityEnrichment {
            $opportunity = Opportunity::query()
                ->with('skills')
                ->where('workspace_id', $workspaceId)
                ->whereKey($opportunityId)
                ->lockForUpdate()
                ->first();

            if ($opportunity === null) {
                throw (new ModelNotFoundException)->setModel(Opportunity::class, [$opportunityId]);
            }

            /** @var Opportunity $opportunity */
            $evaluation = OpportunityEvaluation::query()
                ->where('workspace_id', $workspaceId)
                ->where('opportunity_id', $opportunity->id)
                ->whereKey($evaluationId)
                ->lockForUpdate()
                ->first();

            if ($evaluation === null) {
                throw (new ModelNotFoundException)->setModel(OpportunityEvaluation::class, [$evaluationId]);
            }

            $currentInput = $this->buildInput->execute($opportunity);

            if ($opportunity->review_evaluation_id !== $evaluation->id
                || $evaluation->input_sha256 !== $currentInput->sha256
                || $evaluation->engine_version !== OpportunityScorer::ENGINE_VERSION
                || $evaluation->profile_version !== $activeProfile->version) {
                abort(409, 'The displayed evidence changed. Reload before saving.');
            }

            if ($expectedEnrichmentId !== null && ! OpportunityEnrichment::query()
                ->where('workspace_id', $workspaceId)
                ->where('opportunity_id', $opportunity->id)
                ->where('evaluation_id', $evaluation->id)
                ->whereKey($expectedEnrichmentId)
                ->exists()) {
                throw (new ModelNotFoundException)->setModel(OpportunityEnrichment::class, [$expectedEnrichmentId]);
            }

            $latest = OpportunityEnrichment::query()
                ->where('evaluation_id', $evaluation->id)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();
            $payloadSha256 = hash('sha256', json_encode([
                'full_description' => $fullDescription,
                'overrides' => $overrides,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($latest !== null && $latest->payload_sha256 === $payloadSha256) {
                $precedingEnrichmentId = $latest->revision === 1
                    ? null
                    : OpportunityEnrichment::query()
                        ->where('evaluation_id', $evaluation->id)
                        ->where('revision', $latest->revision - 1)
                        ->value('id');

                if ($expectedEnrichmentId === $latest->id || $expectedEnrichmentId === $precedingEnrichmentId) {
                    return $latest;
                }
            }

            if ($latest?->id !== $expectedEnrichmentId) {
                abort(409, 'The displayed enrichment changed. Reload before saving.');
            }

            $input = TriageInput::fromArray(array_replace($evaluation->input_snapshot, $overrides));
            $result = $this->scorer->evaluate($input, ScoringProfile::fromArray($evaluation->profile_snapshot));
            $revision = $latest === null ? 1 : $latest->revision + 1;

            return OpportunityEnrichment::query()->create([
                'workspace_id' => $workspaceId,
                'opportunity_id' => $opportunity->id,
                'evaluation_id' => $evaluation->id,
                'revision' => $revision,
                'full_description' => $fullDescription,
                'overrides' => $overrides,
                'input_snapshot' => $input->snapshot,
                'result' => $result->toArray(),
                'payload_sha256' => $payloadSha256,
                'input_sha256' => $input->sha256,
            ]);
        });
    }
}
