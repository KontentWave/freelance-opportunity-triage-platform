# ADR-009: Authored Demo Inputs and Public Evaluation Profile

**2026-11-01 completion:** original schema-v2 archive passed candidate-specific HTTPS disable/code rollback/restoration, then separately approved persistent visitor opt-in/v1.0.2 publication. Existing authored corpus/public profile remain unchanged; no new operator mailbox/import/AI/email acceptance operation. Both report acknowledgments and honest partial 19/20 exception/#84 preserved, without a general accuracy claim. [Final evidence and boundaries](../runbooks/phase6-release.md#phase-64-completion-approved-persistent-opt-in-and-v102---2026-11-01).

**2026-11-01 final receipt update, 09:01:46Z:** owner found all emails in the actual protected inbox and explicitly confirmed both original and partial reports. Authorized receipt-only commands recorded separate OPERATOR-REPORTED timestamps/references; SMTP state, immutable snapshots, partial failures and approved exact-run 19/20 exception/#84 are unchanged. No resend, inbox inspection by the agent or transport patch. [Actual receipts and preserved final release gates](../runbooks/phase6-release.md#confirmed-owner-receipt-and-preserved-release-boundary---2026-11-01); public v1.0.1 remains disabled, no opt-in/v1.0.2 yet.

**2026-11-01 exact-run exception:** owner explicitly accepts actual **partial 19/20** run `01m69tea8emstgs8ep4dwj513e` on `e4364291e86f576fc20fcb27890f2abf108b400f`, preserving failed evidence and OPEN [DEMO-004 follow-up #84](https://github.com/KontentWave/freelance-opportunity-triage-platform/issues/84). No corpus, validator, stored output or historical gate result is changed; no general accuracy claim. Both SMTP-accepted reports still lack owner receipt, and downstream cause is unestablished. [Revised decision and separate receipt/release gates](../runbooks/phase6-release.md#owner-exception-and-read-only-delivery-investigation---2026-11-01); public v1.0.1 remains disabled with no new run/send/release.

**2026-10-31 acceptance update:** the integrated schema-v2 exact archive reused the existing approved 20 messages with real TLS/PEEK identities/content/persistent flags preserved; no APPEND or corpus/profile change. New live run finalized partial **19/20**, below the owner-approved 20/20 gate. One new immutable partial-report email was SMTP-accepted without duplicate resend, but the owner reports nonreceipt; no receipt fabricated, activation or release. The original report/accounting and public disabled v1.0.1 remain preserved. [Exact current evidence](../runbooks/phase6-release.md#schema-v2-integration-and-new-bounded-acceptance---2026-10-31).

- **Date:** 2026-10-27
- **Decision owner:** Marcel
- **Status:** APPROVED for planning, including public v2 inputs and final corpus content; host/access and owner-attested configuration prerequisites recorded 2026-10-28, live acceptance deferred
- **Scope:** Slice 6.1 preparation only; no runtime implementation or publication

## Context

Phase 6 evaluates fictional emails against a deliberately public profile, not a visitor's profile. The existing Upwork parser requires exact Upwork senders, `New job alert:` subjects and real-shaped Upwork job identifiers. Relabelling fictional jobs as Upwork alerts would misrepresent their origin. The existing private profile and deterministic evaluation history are not inputs to this feature.

## Decisions

Marcel explicitly approved a new public rubric on 2026-10-27, not reproduction of his private prompt or Scrum-XP framework. Following his review, the approved representations are `public-profile-v2`, `public-rubric-v2` and `public-evaluation-v2` in the [readiness record](../runbooks/phase6-readiness.md#public-profile-and-prompt). Version 2 supersedes the earlier no-iPhone profile and makes outcome-led AI-assisted implementation explicit. There is **no minimum hourly rate**. SKIP requires an evidenced incompatibility; important unknowns mean MAYBE (conditional application/clarification). Manual-only testing remains eligible. Coding or an unfamiliar implementation language alone is not an exclusion.

Prepare, but do not publish or send, [20 authored messages](../../tests/Fixtures/Emails/demo/manifest.json), manifest version `authored-demo-v2`. All jobs, client facts and mail identities are fictional. Final content and the isolated source design are APPROVED for future publication, not publication now. Version 2 changes the reviewed expected band for DEMO-008 to APPLY because the approved public profile includes an iPhone 11; all 20 email payloads and their hashes remain unchanged. The source namespace remains `fotp-authored-demo-v1` to preserve logical source identity across this metadata revision. Malformed/adversarial inputs live in a separate subdirectory and are not demonstration candidates. Human-authored expected bands are rubric examples, not measured AI accuracy.

The reserved `.invalid` envelope addresses are intentionally not routable identities. A later separately authorized operator task may import these exact RFC822 files into the dedicated demo folder using the provider's import facility or IMAP APPEND; do not rely on SMTP delivery from a nonexistent sender or impersonate a marketplace. Neither import nor APPEND is performed or authorized by this slice. Read-only retrieval must then preserve flags/content.

### Smallest explicit demo-source adapter

APPROVED design for Slice 6.2: a demo-only parser/normalizer consumes UTF-8 `text/plain` JSON in RFC822 messages:

- One exact synthetic sender `demo-alerts@example.invalid`; subject prefix `FOTP Fictional alert: `.
- Required body `format="fotp-authored-alert-v1"`, `synthetic=true`, `source_id` matching `DEMO-[0-9]{3}`, nonempty title and description. Header ID is `<DEMO-NNN@fotp.example.invalid>` and must agree with the body identity.
- Fields and types are restricted to the manifest's `body_fields`; missing optional values normalize to null, never invented facts. No attachments, HTML-only body, arbitrary keys or executable content. Reject malformed, conflicting or unapproved content with a safe code.
- Source URL is absent. Reports show a fictional source ID, not a live listing or an Upwork link. Any future links require a separate explicit allowlist; no model URL fetching.
- Require a **server-configured dedicated demo inbox**, demo workspace/mode, approved manifest membership and matching normalized body hash in addition to the envelope checks. The manifest's `normalized_body_sha256` means canonical decoded authored JSON **before** filling omitted optional fields with null; the later normalized AI-input snapshot has its own hash. Follow the manifest's canonicalization exactly. Sender/subject alone are not authorization. If mail software changes the canonical content, quarantine it and reapprove the corpus rather than weakening checks.
- Bind this adapter explicitly in the demo workflow. Do not replace the global `OpportunityEmailParser` binding, extend the private sender allowlist, fabricate `UpworkEmail` records, or route through the private importer. Reuse MIME parsing, bounded TLS/PEEK transport primitives and workspace scoping where appropriate.

The current `MailboxClient::discover()` is forward-checkpointed, sorts ascending and performs SEARCH before slicing locally. It does **not** implement newest-eligible bounded scanning. Add a demo-specific discovery boundary, without changing private discovery semantics.

APPROVED selection: EXAMINE only the protected demo folder; snapshot UIDVALIDITY and UIDNEXT, inspect at most the last **100 numeric UID positions**, descending UID, with metadata/header fetches limited to that window and at most **100 bodies / 6,553,600 bytes**. Do not enumerate an entire mailbox with SEARCH ALL. Select at most 20 approved unique sources in descending UID order; UID is the stable tie-breaker within this fixed namespace (not the untrusted Date header). This defines "latest" as mailbox arrival order. Holes, duplicates, malformed and nonmatching candidates can produce fewer than 20; report bounded-scan counts, not a claimed total inbox count. Do not scan older mail to fill gaps.

Transport identity is `(workspace, demo_mailbox_key, UIDVALIDITY, UID)`. Logical identity is `(source_namespace, source_id, normalized_body_sha256)`; Message-ID and raw hash are secondary evidence, not the sole dedupe key. Repeated identical sources within a snapshot count once. Same ID with different content is rejected, not silently updated. UIDVALIDITY change during retrieval invalidates selection; fail safely and require a new bounded snapshot, never reuse the old numeric UID against a new namespace. Use BODY.PEEK, verified TLS and pre-body RFC822.SIZE checks. Never mark read, move, delete, EXPUNGE or access genuine alerts. No mailbox messages are populated in this slice.

### Reports and owner delivery

APPROVED: seven-day generated-report retention and matching read-only on-screen/downloadable HTML from one immutable snapshot. Label every report "Fictional demo alerts evaluated against Marcel's public profile"; include versions, alert time separately from evaluation time, actual counts, failures/unknowns and expiry. Escape all text; downloadable HTML has a print stylesheet, no scripts, remote assets or tracking.

APPROVED: no visitor email collection or sending endpoint. Later owner delivery is operator-only, uses the stored report once, and records delivery separately from AI state. Actual recipient, verification evidence and credentials remain protected configuration. On 2026-10-28, protected mailbox authentication and recipient configuration shape were VERIFIED; the owner confirmed control of the exact staged recipient and approved sole-recipient use (OPERATOR-REPORTED). These satisfy planning prerequisites, not tested delivery or implemented allowlisting. See the [protected configuration checklist](../runbooks/phase6-readiness.md#protected-configuration-prerequisites).

## Consequences

### 6.4 owner-delivery implementation

`demo-ai:deliver-owner {run}` is operator-only, synchronous and sends the existing `demo-report-v1` snapshot through the same escaped HTML representation as the screen/download. No queue mail job, public sending route or recipient argument exists. Protected configuration supplies the sole address, exact allowlist digest, version and owner verification reference; the existing owner-control attestation is OPERATOR-REPORTED, not machine-verified inbox receipt. See the [delivery states and execution plan](../runbooks/phase6-release.md). Actual transport acceptance and owner receipt remain NOT VERIFIED.

AI results need their own immutable snapshots, not overwrites of deterministic scores, enrichment or feedback. Source/prompt/profile changes require new version/hash identities. A deliberate new run may re-evaluate approved sources; HTTP retries and job redelivery may not duplicate paid work. No real-alert republication, capability invention, profile personalization or private-source fallback is permitted.

### 6.4 bounded live evidence - 2026-10-30

Exactly 20 approved `authored-demo-v2` messages were APPENDed once into the dedicated folder; real certificate-validated EXAMINE/BODY.PEEK selected their pinned identities/hashes and preserved persistent flags/raw content. No genuine alerts, alternative corpus or private source adapter was used. The single run produced **no accepted recommendations** after its permitted attempts; no general accuracy or expected-band success is claimed. See the [actual failed acceptance](../runbooks/phase6-release.md#resumed-bounded-execution---2026-10-30).

One existing finalized **failure report** was SMTP-accepted through the operator-only command; its duplicate trigger did not send another copy. **Owner nonreceipt is OPERATOR-REPORTED**, `receipt_reported_at` remains null, and no `--receipt` command was fabricated. The owner's later source/sender clarification already matched the protected project mailbox, with the sole recipient distinct; no identity/configuration change or resend followed. The one-email allowance is consumed. Public runtime remains disabled and v1.0.1 restored; failed acceptance is not opt-in/release authorization.

### Evidence schema clarification - 2026-10-31

The separately approved DEMO-001 diagnostic returned exact job quotes under bare `description`, while the validator requires `input.description`; correctly prefixed profile references were present. Prefix-only offline control passes the unchanged validator, establishing this case's namespace ambiguity, not general quality or all historical failure causes. The approved local correction versions only the output transport schema to `ai-evaluation-v2`, closes evidence fields to actual non-null `input.*` / `profile.*`, and closes missing-information IDs to the existing bare input fields/criteria. Public prompt/profile/rubric/corpus remain unchanged; no live output is normalized or invented. Admission and request hashes consequently change, while original run snapshots/reports stay immutable. Local provider/counting regressions pass; correction is not integrated/deployed/live accepted. [Exact diagnostic and scope](../runbooks/phase6-release.md#one-item-diagnostic-and-offline-schema-correction---2026-10-31).

## References

- [ADR-004: private transport and historical host evidence](ADR-004-scheduled-imap-polling.md)
- [ADR-005: deterministic provenance](ADR-005-deterministic-opportunity-triage.md)
- [ADR-007: immutable review history](ADR-007-review-dashboard-and-manual-enrichment.md)
- [Slice 6.1 evidence and blockers](../runbooks/phase6-readiness.md)
