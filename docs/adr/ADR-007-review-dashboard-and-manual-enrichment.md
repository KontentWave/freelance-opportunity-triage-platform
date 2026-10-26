# ADR-007: Review Dashboard and Manual Enrichment

- **Status:** Accepted and implemented for Phase 4 Slice 2
- **Date:** 2026-09-16
- **Decision owners:** Project and quality engineering

## Context

Phase 3 stores immutable email-only evaluations and one human review per evaluation. Phase 4 needs to let the workspace owner paste private full-description text, explicitly confirm structured facts, see a revised suggestion, and record informed feedback without changing the historical email evidence or misrepresenting calibration.

Concurrent browser tabs, mailbox updates, profile changes, and repeated requests can make a displayed context stale. The write paths must remain workspace scoped, idempotent where appropriate, and free of partial writes. The first real Phase 3 calibration remains a completed failed experiment; this dashboard work proceeds only under the recorded portfolio-demonstration decision.

## Decision

Email evaluations remain immutable. Manual results are append-only `opportunity_enrichments` attached to one base email evaluation. Each row stores the normalized full description, sparse confirmed overrides, merged input snapshot and hash, saved result, payload hash, and a monotonically increasing revision. Omitted overrides inherit from the base email snapshot; explicit null, false, zero, and empty-list values retain their distinct meanings. Prose is never parsed or scored.

The latest enrichment for the active base evaluation becomes the displayed suggestion. The original email result remains available beside it. Repeating the latest normalized payload returns the existing row, including a retry whose expected ID is the immediately preceding revision. Returning deliberately from payload A to A after an intervening B appends a new revision.

Both dashboard mutations use optimistic displayed-context identifiers inside database transactions. They derive workspace ownership from the authenticated opportunity and resolve nested evaluations and enrichments under that parent. They lock the opportunity first, then the base evaluation, and then read or lock the latest enrichment or current review. They recheck the selected evaluation, normalized live input hash, scorer engine, active profile version, and latest enrichment before writing. Foreign or absent nested identifiers return 404; a valid but outdated context returns a safe 409.

Human feedback continues to use the single review row for the base email evaluation. Dashboard writes explicitly replace enrichment provenance, label, reason, notes, outcome, sample kind, and review timestamp together. Identical writes are no-ops. Existing CLI calls remain valid: omitted dashboard details preserve notes and outcome, while a changed CLI label or reason clears enrichment provenance so it cannot imply that the revised judgment used manual details.

A disagreement reason is determined against the original email recommendation, not the enriched suggestion. Calibration continues to join that original machine label with the human label and sample provenance. Enrichment results, notes, and outcomes never replace email evaluations or enter the machine side of Phase 3 reports.

Any exception rolls back the transaction. Stale base input, profile, evaluation pointer, or enrichment context therefore creates no enrichment revision and does not partially update feedback. The UI retains drafts in memory and announces success only after the server returns the persisted current context.

## Consequences

- Email evidence and Phase 3 calibration semantics remain historically reproducible.
- Manual facts can improve the displayed suggestion without mutating imported opportunity fields.
- New enrichment revisions invalidate older feedback as current while preserving the older review row.
- The fixed lock order reduces deadlock risk across enrichment and feedback writes.
- Full descriptions and notes remain private application data and are not included in logs or published evidence.
- A fresh base evaluation does not replay old manual overrides; prior enrichments remain historical only.

## Rollback

Application rollback removes access to the Slice 2 routes and compiled form assets while preserving additive enrichment and review data. Operational rollback does not run destructive down migrations against personal data. A forward fix restores access or adjusts the additive schema without rewriting immutable email evidence.

## Phase Boundary

ADR-006 remains reserved. This decision does not add synthetic demo seeding or presets, credential-free demo entry, Playwright installation or final browser acceptance, deployment, scoring/profile changes, automatic prose extraction, marketplace access, or mailbox HTTP behavior. Those remaining demonstration and release activities belong to Phase 4 Slice 3 and Phase 5.
