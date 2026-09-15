<?php

namespace App\Application\Triage;

use App\Domain\Triage\Data\TriageInput;
use App\Models\Opportunity;

class BuildOpportunityTriageInput
{
    public function execute(Opportunity $opportunity): TriageInput
    {
        $opportunity->loadMissing('skills');

        return TriageInput::fromArray([
            'contract_type' => $opportunity->contract_type,
            'currency' => $opportunity->currency,
            'hourly_max' => $opportunity->hourly_max,
            'skills' => $opportunity->skills->pluck('name')->all(),
            'hidden_skill_count' => $opportunity->hidden_skill_count,
            'payment_verified' => $opportunity->payment_verified,
            'client_rating' => $opportunity->client_rating,
        ]);
    }
}
