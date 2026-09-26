# ADR-008: Bounded Portfolio Release Promotion

- **Status:** Implemented workflow; publication pending operator acceptance
- **Date:** 2026-09-26
- **Decision owners:** Project and quality engineering

## Context

The Phase 4 demo is accepted, while the first real calibration failed its product feasibility targets. Phase 5 requires a reproducible public engineering demonstration without bundling private data or equating a produced CI artifact with a passing build. The release must preserve the same tested archive through host smoke and publication.

## Decision

The existing MariaDB test job uses locked Composer/npm installations and pins Syft 1.32.0 with a verified official binary checksum. Its offline filesystem scan captures installed PHP and JavaScript dependencies, including development tooling; the CycloneDX record labels itself a build/dependency inventory, not an exact minimal production footprint. After the existing gates and browser acceptance, the job copies only safe tracked commit blobs and inspected Vite build assets into a four-file candidate. PRs exercise this path without upload; only a main push uploads a 30-day artifact named by full SHA and run attempt. The complete CI run must still pass Quality, Tests / MariaDB 11.4 and Secret scan.

A manually dispatched workflow, run from main, first validates the selected push-to-main CI run and latest completed attempt with read-only permissions. It checks main ancestry, the matching unexpired artifact, required job outcomes, manifest, archived locks and inventory versions, all three checksums, and the operator-attested timestamp and archive hash. Its write-scoped job revalidates and downloads the same candidate, creates a draft release at the exact SHA with the four assets, then publishes it. Inputs are data, not executable shell fragments. Serialization does not cancel a publication in progress. A prior tag or release blocks automatic retries; a partial draft/tag is left for explicit inspection.

The approved synthetic host must smoke the **archive being released**, rehearse code/assets rollback without database loss, and retain sanitized evidence before dispatch. CI does not inspect the host or make its own smoke attestation. There is no host/mailbox credential in the workflow and no extra runtime dependency or app behavior change.

## Consequences

- Build/dependency inventory can include dev dependencies and does not prove a minimal production footprint or freedom from undiscovered vulnerabilities.
- Archive privacy still requires a bounded human review of the exact assets before publication; checksums and secret scanning are insufficient by themselves.
- The earlier host smoke and failed calibration remain historical; they do not qualify this candidate or establish product feasibility.
- After draft creation, a publication failure requires deliberate operator recovery rather than a destructive automatic retry.

## Rollback

On the disposable synthetic host, restore the previous known-good code and compiled assets, preserving configuration, database rows and shared storage. Verify saved feedback/enrichment before returning to the candidate. Do not run down migrations or reset private data. See the [release runbook](../runbooks/phase5-release.md).
