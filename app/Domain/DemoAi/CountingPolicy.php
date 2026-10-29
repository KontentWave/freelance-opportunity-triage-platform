<?php

namespace App\Domain\DemoAi;

final class CountingPolicy
{
    public const VERIFIED_ZERO_CHARGE = 'verified_zero_charge';

    public const ACCEPTED_RISK = 'owner_accepted_risk';

    public const OWNER_DECISION = 'ADR-010:2026-10-29:accepted-counting-risk';

    /** @return array{mode: string, evidence_sha256: ?string, risk_acceptance_reference: ?string} */
    public function snapshot(): array
    {
        $mode = config('demo_ai.counting_policy_mode');
        $evidence = config('demo_ai.counting_policy_evidence_sha256');
        $acceptance = config('demo_ai.counting_risk_acceptance_reference');
        if (! in_array($mode, [self::VERIFIED_ZERO_CHARGE, self::ACCEPTED_RISK], true)
            || ($evidence !== null && ! is_string($evidence))
            || ($acceptance !== null && ! is_string($acceptance))) {
            throw new DemoAiException('demo_ai.token_bound_unverified');
        }

        return ['mode' => $mode, 'evidence_sha256' => $evidence, 'risk_acceptance_reference' => $acceptance];
    }

    public function assertAvailable(): void
    {
        $policy = $this->snapshot();
        $verified = $policy['mode'] === self::VERIFIED_ZERO_CHARGE
            && is_string($policy['evidence_sha256'])
            && preg_match('/^[a-f0-9]{64}$/D', $policy['evidence_sha256']) === 1
            && ($policy['risk_acceptance_reference'] === null || $policy['risk_acceptance_reference'] === '');
        $accepted = $policy['mode'] === self::ACCEPTED_RISK
            && $policy['risk_acceptance_reference'] === self::OWNER_DECISION
            && ($policy['evidence_sha256'] === null || $policy['evidence_sha256'] === '');
        if (config('demo_ai.counting_enabled') !== true || (! $verified && ! $accepted)) {
            throw new DemoAiException('demo_ai.token_bound_unverified');
        }
    }
}
