# Reliability and Dependability — Remediation Evidence

This report records current remediation separately from the unchanged [original reliability audit](../04-reliability-dependability.md) and [historical audit summary](../README.md). Finding IDs and accepted Phase 1–5 evidence remain unchanged. Append subsequent dated entries; do not replace earlier observations.

## REL-01 — Private operator provisioning and authenticated workflow

**Original finding:** [REL-01 in Audit 04](../04-reliability-dependability.md#confirmed-defects-by-severity).

**Current status:** **CLOSED / VERIFIED**, 2026-10-11.

**Deployed version:** `v1.0.0`, `a00803b5947b7f52e18537e46edab86a949331da`.

**Final host persistence verification:** `2026-10-11T20:07:00Z`.

### Progress entry — 2026-10-11: target and provisioning

- The initial `git status --short` was clean. Existing authorized SSH access was reused; no hosting access or deployment configuration was changed.
- The active private release directory matched the full deployed SHA. Hashing the same tracked files under `app/`, `routes/`, `config/`, `database/`, `resources/`, `bootstrap/`, plus `public/index.php` and `composer.*`, produced identical local/tag and deployed digests: `25d7ad7ca54223c33c349d6c430ff61d5b3af9a458a751acdc7d29962283ba03`. This checks those runtime files, not every installed dependency or asset.
- Effective configuration was production, debug off, private review mode, and mailbox intake disabled. The connected MariaDB version was `11.4.13`; the database had neither `_demo` nor `_test` suffix. The effective stage database was independently checked as demo mode with `_demo` suffix and a different database fingerprint. Database names and fingerprints are omitted here.
- The sole existing workspace matched the explicitly configured intake workspace. All 52 pre-existing opportunities belonged to it. The personal scoring profile loaded successfully without printing its definition.
- Fresh account checks still found zero users and zero assigned users. Those observations justified provisioning; September counts were not assumed current and did not trigger bulk evaluation or population.
- The operator executed temporary, guarded tooling through a trusted interactive SSH terminal. Both email and password inputs were hidden; Laravel-supported hashing was used. The tooling explicitly assigned the verified existing workspace, refused a mismatched existing account or concurrent provisioning, and did not use a seeder or factory password.
- The operator reported successful provisioning and entered login credentials directly in the private browser interface. A subsequent host check independently confirmed exactly one operator, its matching workspace, and a supported password hash. No existing password was reset.

### Closure entry — 2026-10-11: private-host journey

These results are from the actual private deployment, not local tests or staging acceptance.

| Check | Result | Evidence / scope |
| --- | --- | --- |
| Deployed version, private database and intended workspace | PASS | Current release, tracked runtime digest, effective configuration and workspace checks above. |
| Operator provisioned with supported hashing and explicit workspace | PASS | One assigned operator independently verified after interactive provisioning. |
| Login reaches the expected workspace queue | PASS | Operator completed direct interactive login; the shared browser reached the private review queue. Authenticated queue JSON returned 200, with 53 records after adding the single fixture. |
| Synthetic opportunity detail | PASS | Browser reached the clearly labeled REL-01 fixture in the assigned workspace. |
| Evaluate only the fixture when needed | PASS | The fixture began unscored; its browser evaluation request returned 200. Exactly one fixture evaluation persisted. |
| Save enrichment | PASS | Browser save returned 200; synthetic description and confirmed maximum hourly rate `45.00` persisted. |
| Save feedback with `sample_kind=demo` | PASS | Browser save returned 200 with demo provenance; host readback confirmed `demo`, `SKIP`, and `not_applied`. |
| Reload and verify enrichment and feedback | PASS | Browser reload preserved description, confirmed rate, label, provenance, outcome and synthetic note. JSON readback and database checks confirmed feedback references the saved enrichment. |
| Logout | PASS | Browser sign-out returned to `/login`. |
| Protected review JSON after logout | PASS | Same-browser `GET /review/v1/opportunities`, with `Accept: application/json`, returned 401 and JSON content type. The expected browser-console 401 is not an application defect. |
| Preserve original opportunity data and immutable history | PASS | Ordered aggregate row digests and counts before/after matched for all original opportunities, skills, evaluations, enrichments and reviews, excluding only the new fixture and its linked rows. |
| Calibration exclusion | PASS | Saved review provenance is `demo`; a read-only report selecting only the fixture evaluation returned `DEMO_ONLY` and zero real samples. No real cohort was assembled or modified. |
| Intake remains disabled | PASS | Effective flag rechecked after the journey. No poll, connectivity, mailbox import or marketplace operation was invoked. |
| Complete browser request-capture evidence | NOT VERIFIED | Browser event callbacks reported zero requests despite known HTTP activity, so their counters are unusable and are not evidence of zero network requests. Post-logout Resource Timing independently showed two same-origin resources and no mailbox/marketplace resource, but covers only that document, not the full journey. |

The initial verification harness incorrectly compared the filtered unscored queue count (23) with the unfiltered total (53). The corrected unfiltered check passed before browser writes. This was a verification assertion error, not an application defect; no application changes were made.

All provisioning and required authenticated workflow checks passed. The ancillary full request-capture diagnostic is explicitly not claimed as verified; it does not substitute for, or invalidate, the verified login/save/reload/logout/401 results.

### Synthetic fixture disposition

- Retained as one clearly labeled **REL-01 synthetic operator verification** opportunity, provider `synthetic_demo`, external marker `rel-01-20261011`, source template `rel-01-operational-smoke`. Its non-marketplace synthetic URL is not exposed as an actionable marketplace link.
- Final fixture history: one evaluation, one enrichment at revision 1, one review bound to that enrichment. Outcome is `not_applied`; no genuine outcome was changed. Existing history was not deleted or rewritten.
- Exclude this fixture and every linked evaluation from real calibration selections. Persisted `sample_kind=demo` additionally makes a report containing it `DEMO_ONLY`, even under a personal profile; this is not evidence of feasibility or a real calibration GO.
- Temporary provisioning tooling and aggregate-only preservation checkpoints were removed after verification. Credentials, account identifiers, private hostnames, private profile definitions and genuine opportunity content are excluded from this evidence.

## REL-03 — Recurring backup coverage

### Status entry — 2026-10-11

**OPEN / NOT VERIFIED.** The [original REL-03 finding](../04-reliability-dependability.md#confirmed-defects-by-severity) remains outstanding. This task did not verify provider snapshots, establish scheduled dumps, check rotation, or perform a new restore. The historical successful restore proves only that single dump's restorability, not recurring coverage.

Confirm and document provider snapshot coverage, or separately authorize and verify a scheduled dump with checksum and rotation, before relying on the tool day-to-day. The production-use verdict remains **CONDITIONAL GO**.
