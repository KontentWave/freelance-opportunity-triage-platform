# ADR-005: Deterministic Opportunity Triage

- **Status:** Accepted and implemented for Phase 3
- **Date:** 2026-09-09
- **Decision owners:** Project and quality engineering

## Context

The accepted Phase 2 baseline imports supported direct-link alerts into workspace-owned normalized opportunities. Phase 3 needs an explainable APPLY, MAYBE, or SKIP suggestion without claiming that an email summary proves full suitability, specialist capability, credentials, availability, or weekly capacity.

The application must preserve old decisions as evidence when opportunity data or preferences change. It must also remain deterministic, offline, workspace isolated, and small enough to calibrate before any dashboard investment.

## Decision

Use one pure `triage-v1` scorer with four fixed rules: exact normalized skill preference, known hourly USD maximum, payment verification, and client rating. Profiles are bounded local JSON documents. Their canonical normalized content hash is the profile version; there is no profile table, rule language, remote profile source, current exchange rate, randomness, or network dependency.

Persist each evaluation with the complete normalized profile snapshot, the seven-field normalized input snapshot, their hashes, and the complete explainable result. The unique identity is workspace, opportunity, engine version, profile version, and input hash. Repeating that identity returns the immutable existing row. A changed profile or normalized input appends history. The unique database constraint is the concurrency backstop; a losing insert retrieves the matching workspace-scoped row.

The CLI accepts one explicit workspace, opportunity, and profile path. It validates the complete profile before querying the opportunity, accepts only readable local regular files of at most 64 KiB, rejects stream wrappers, and emits only allowlisted stable error codes. JSON output contains one object and no progress chatter.

Every recommendation requires manual review. APPLY means prioritize review, MAYBE means retain for review, and SKIP means suggest deprioritizing. No label deletes or hides an opportunity. Output reminds the operator to inspect the full scope, verify credible delivery capability, and confirm the work fits within Marcel's maximum 20 hours per week before applying.

One current review is stored per evaluation. Identical submissions are no-ops; an explicit changed submission replaces the label, reason, sample kind, and review timestamp without mutating the machine evaluation.

Calibration accepts only an explicit local JSON cohort of 1–100 unique evaluation ULIDs. The workspace-scoped report action rejects mixed engine/profile versions and repeated opportunities, then passes aggregate samples to a pure calculator. Reports contain the full 3×3 matrix, skip-vs-review counts and rates, missing-field and disagreement-reason counts, evidence status, and an integer-boundary target decision. No report table or automatic cohort selection is introduced.

## Consequences

- Historical results can be reproduced from stored snapshots with the matching scorer semantics.
- Formatting-only profile changes preserve identity; normalized content changes create history.
- Workspace-scoped lookup gives the same `triage.not_found` result for absent and foreign identifiers.
- Exact skill matching is a preference signal, not proof of capability or a hard exclusion.
- Unknown evidence produces MAYBE unless the known hourly USD maximum is below the floor.
- The synthetic demo profile demonstrates behavior but cannot establish personal preference quality or product feasibility.
- Personal profiles, genuine cohort identifiers, and labels stay in ignored private storage; only aggregate report evidence may be published deliberately.

## Phase Boundary

This decision adds no mailbox behavior, scheduler integration, HTTP request, queue, UI, unauthenticated route, full-description enrichment, automated application, or dashboard. The accepted Phase 2 direct-link-only intake and its operational evidence remain unchanged. Phase 3 implementation is deployed, and a validated private personal profile exists. Real calibration and any GO/stop decision remain pending completion and human labeling of the specified bounded cohort.
