<?php

namespace App\Application\Triage;

use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use App\Domain\Triage\OpportunityScorer;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class EvaluateOpportunity
{
    public function __construct(
        private readonly OpportunityScorer $scorer,
        private readonly BuildOpportunityTriageInput $buildInput,
    ) {}

    public function execute(
        string $workspaceId,
        string $opportunityId,
        ScoringProfile $profile,
    ): OpportunityEvaluation {
        $opportunity = Opportunity::query()
            ->with('skills')
            ->where('workspace_id', $workspaceId)
            ->whereKey($opportunityId)
            ->first();

        if ($opportunity === null) {
            throw new TriageException(TriageErrorCode::NotFound);
        }

        $input = $this->buildInput->execute($opportunity);
        $identity = [
            'workspace_id' => $workspaceId,
            'opportunity_id' => $opportunity->id,
            'engine_version' => OpportunityScorer::ENGINE_VERSION,
            'profile_version' => $profile->version,
            'input_sha256' => $input->sha256,
        ];
        $existingEvaluation = OpportunityEvaluation::query()->where($identity)->first();

        if ($existingEvaluation !== null) {
            return $existingEvaluation;
        }

        $result = $this->scorer->evaluate($input, $profile);

        try {
            return DB::transaction(fn (): OpportunityEvaluation => OpportunityEvaluation::query()->create($identity + [
                'profile_snapshot' => $profile->definition,
                'input_snapshot' => $input->snapshot,
                'result' => $result->toArray(),
                'recommendation' => $result->recommendation,
                'score' => $result->score,
            ]));
        } catch (QueryException $exception) {
            $concurrentEvaluation = OpportunityEvaluation::query()->where($identity)->first();

            if ($concurrentEvaluation !== null) {
                return $concurrentEvaluation;
            }

            throw $exception;
        }
    }
}
