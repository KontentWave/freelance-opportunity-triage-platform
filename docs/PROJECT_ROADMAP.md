# Project Roadmap — Freelance Opportunity Triage Platform

**Status:** Phases 1 through 5 completed as a portfolio release. Phase 5's exact CI archive passed isolated host smoke, bounded asset review and same-demo-database rollback rehearsal before the separately approved `v1.0.0` publication on 2026-09-28; the published tag and four assets were verified against the candidate. The same candidate was subsequently deployed to the separate private `fotp` site with its original configuration and data retained; the synthetic stage remains separate. Phase 3's first real calibration remains an unsuccessful feasibility result; optional Tester Skill deferred until after MVP.
**Primary user:** An independent freelancer reviewing opportunities from authorized job-alert emails  
**Working title:** To be decided; do not use Upwork trademarks in product branding

**Planning update — 2026-10-26:** Phase 6: Live AI Evaluation & Reports is accepted for planning; implementation, live-service verification and release acceptance are pending. The optional reusable Tester Skill remains deferred. Phase 6 visitors inspect an evaluation against Marcel’s approved public profile.

**Deployment context:** The [project sheet](project_sheet.md) records the public portfolio move to [https://fotp.kontentwave.com/demo](https://fotp.kontentwave.com/demo) on 2026-10-25 and the unchanged REL-01/REL-03/REL-04 closures. These and the baseline deployment statements above are historical records, not newly repeated checks.

## Vision

Build a portfolio-quality personal tool that converts job-alert emails delivered to the user's own mailbox into an explainable, prioritized review queue. The platform should reduce low-value manual browsing while leaving all marketplace access, final judgment, and proposal submission under human control.

The first source is Upwork Freelancer Plus email alerts, but the design should remain provider-agnostic. A source adapter normalizes each alert into a common opportunity model; deterministic rules then classify it as **APPLY**, **MAYBE**, or **SKIP**, with reasons that the user can inspect and refine.

Phase 6 extends that completed baseline with visitor-triggered AI evaluation of authored demo alerts and reports. Publish the approved public criteria and label whose profile is evaluated; retain human judgment and the distinction between technical integration success and recommendation quality.

## Problem and Value Hypothesis

Job alerts contain enough summary and client-economics data to reject many unsuitable opportunities without opening the full marketplace listing. If the system can safely eliminate at least half of manual opens while rarely hiding a genuinely suitable job, it provides meaningful value even without marketplace API access.

This is a hypothesis to validate, not an assumption to defend. Phase 3 includes an explicit go/no-go calibration checkpoint.

**Post-MVP decision — 2026-10-26:** The first calibration did not meet the feasibility targets, and the owner’s usage indicates that alert delay limits timely applications. Manual skimming is the primary discovery path; official marketplace API integration is deferred. Phase 6 is an engineering demonstration with a separate acceptance gate, not proof that the original value hypothesis succeeded.

## Product Principles

- **Compliance boundary:** Process emails the user is authorized to receive. Do not scrape marketplace pages, bypass access controls, automate proposals, or imitate human browsing.
- **Human-in-the-loop:** Automation triages; the user reviews shortlisted jobs and submits proposals manually.
- **Explainable first:** Every classification must show the rules, inputs, contributions, and missing data behind it.
- **Phase 6 AI explainability:** AI suggestions show input evidence, reasons, missing information and prompt/profile/model versions; do not imply that AI output is deterministic.
- **Fail uncertain:** Missing or ambiguous data should produce `MAYBE` or `UNKNOWN`, not an unjustified rejection.
- **Fixture-driven reliability:** Real, sanitized email fixtures and contract tests protect against template drift.
- **Tenant-ready, not SaaS-first:** Include workspace ownership and isolation in the data model without building billing or complex tenant administration in the MVP.

## Scope

### MVP Goals

- Import and normalize supported job-alert emails.
- Deduplicate alerts and preserve their canonical job identifiers and links.
- Score technical fit, client economics, and risk using editable deterministic rules.
- Present a ranked review queue with clear APPLY/MAYBE/SKIP explanations.
- Allow manual full-description enrichment and a final re-evaluation.
- Record the user's actual decision so scoring quality can be measured.

### Explicit Non-Goals

- Scraping Upwork or bypassing Cloudflare, CAPTCHAs, authentication, or rate limits.
- Automatically opening marketplace pages, submitting proposals, or spending Connects.
- Claiming complete marketplace coverage; alerts are personalized and incomplete.
- AI-generated proposals in the MVP.
- Commercial multi-user SaaS, subscriptions, billing, or public Upwork integration.
- Depending on an Upwork API key. A future official adapter requires approved access.

### Planned Post-MVP Scope — Phase 6

Live AI Evaluation & Reports adds authored demo-mail retrieval, profile-specific AI suggestions, progress and downloadable reports, plus owner-only test email delivery. It does not introduce visitor contact collection, real-alert republication or a commercial SaaS. The detailed [Phase 6 specification](project_sheet.md#phase-6-live-ai-evaluation--reports) is in the project sheet; the existing MVP scope and evidence remain historical.

## Technical Baseline

- **Backend:** Laravel 13 on PHP 8.4
- **Database:** MariaDB 11.4
- **Frontend:** Vue with Vite; Node.js 22.23 for the frontend toolchain
- **Deployment:** Provider-hosted web application with scheduled execution; exact cron and outbound IMAP capabilities must be verified
- **Email intake:** IMAP over TLS from a dedicated mailbox or dedicated folder; credentials remain environment secrets
- **Tenancy model:** Shared schema with mandatory `workspace_id` ownership and tested isolation

Node.js is not a separate production service by default. A Node-based mail worker remains an ADR option only if the hosting environment or PHP mail tooling makes it materially safer or simpler.

Phase 6 planned integration: a replaceable AI-provider adapter and durable, bounded background jobs within the Laravel monolith. Keep public demo and private source configuration separate; preserve deterministic scoring as the accepted baseline. Confirm the host execution mechanism during Slice 6.1 rather than presuming a new service or stack migration.

## Success Signals

### Product

- At least **50% of alerts can be rejected without opening the marketplace page** during calibration.
- No more than **5% false-negative rate** among opportunities the user later labels suitable; both targets are provisional until the first 30-alert dataset exists.
- Every classification includes human-readable reasons and identifies missing inputs.
- A newly received supported alert appears in the review queue within the hosting scheduler's agreed interval.

### Quality

- All sanitized fixtures parse deterministically and idempotently.
- Parser and scoring-domain test coverage is at least **90%**; overall coverage target is at least **80%**.
- Cross-workspace access tests prove tenant isolation for every opportunity query and mutation.
- CI blocks merges on formatting, static analysis, unit/integration tests, dependency audit, and secret scanning.

### Portfolio

- A reviewer can run a safe demo without Gmail or Upwork credentials.
- The repository contains architecture decisions, BDD scenarios, tests, seeded demo data, screenshots, and a concise deployment/runbook guide.
- The project explains its compliance boundary and limitations honestly.
- Phase 6 target: a visitor can trigger a bounded real demo-email/AI run, inspect explainable profile-specific results and download the report without providing credentials or an email address. Live, cached and static-demo evidence are clearly distinguished.

## Phased Delivery

### Phase 1 — Fixture-Driven Email Normalization (Completed)

**Hypothesis:** The raw alert emails expose a sufficiently stable structure to create a dependable normalized opportunity record.

**Capabilities:**

- Accept a raw `.eml` fixture without contacting Gmail or Upwork.
- Authenticate the expected source structurally where fixture data permits, without trusting display names alone.
- Extract source message ID, canonical job ID/link, title, work type, rate range, estimated duration, posted date, excerpt, visible skills, payment verification, client rating, client spend, and country.
- Represent missing values explicitly; treat `$0.00–$0.00` as unknown rather than a real zero rate.
- Deduplicate by source and job/message identity.
- Quarantine malformed or unsupported messages with a safe diagnostic reason.

**Exit criteria:**

- The three current raw Upwork fixtures produce the expected normalized records.
- Duplicate import creates no second opportunity.
- Unknown rate, truncated content, HTML entities, and `+N more` skills are covered by tests.
- Unexpected sender/template inputs fail safely without leaking raw email content.
- The normalized opportunity contract is documented and database-independent.

**Candidate BDD features:**

- `Parse a supported hourly job alert`
- `Represent an unavailable hourly rate as unknown`
- `Ignore a duplicate job alert`
- `Quarantine an unsupported or malformed email`

### Phase 2 — Secure Scheduled Mailbox Intake (Completed)

**Hypothesis:** The hosting provider can fetch new alerts securely and reliably without a permanent background worker.

**Capabilities:**

- Connect to a dedicated IMAP mailbox/folder over TLS using environment-managed credentials.
- Fetch only candidate alert messages and submit them to the Phase 1 normalizer.
- Support direct canonical job links only; safely quarantine redirect-only alerts as `email.missing_job_id` without contacting Upwork.
- Use at-least-once processing with idempotency, bounded retries, and failure quarantine.
- Record operational metadata without storing unnecessary mailbox headers or credentials.
- Run through the provider's scheduler/cron and expose a health result.
- Apply the implemented polling budget and bounded external-operation timeouts. The accepted Phase 2 implementation does not establish an absolute database runtime guarantee; further database deadline hardening is deferred.

**Exit criteria:**

- Hosting IMAP endpoint, TLS mode, and cron interval are verified.
- A 24-hour staging soak imports alerts without duplicates or message loss.
- Temporary IMAP failures recover automatically; permanent failures are visible and actionable.
- Production secrets are absent from source control, logs, fixtures, and screenshots.
- Deadline regressions, protected CI, and target-host verification pass for the corrected implementation.

**Candidate BDD features:**

- `Import a newly received alert from the dedicated mailbox`
- `Retry a temporary mailbox failure without duplicating jobs`
- `Quarantine an unsupported message`

### Phase 3 — Explainable Triage and Feasibility Gate

**Implementation status:** Complete. The first real personal-profile calibration was `READY` with 30 reviewed imports, a 3.33% machine skip rate, a 9.09% false-negative rate, and `targets_met=false`. It did not establish a feasibility GO.

**Hypothesis:** Email-only data can remove a meaningful portion of manual review without unacceptable false negatives.

**Capabilities:**

- Define versioned scoring profiles for technical/project fit, client economics, and risk signals.
- Support hard exclusions, weighted preferences, missing-data handling, and score thresholds.
- Produce APPLY/MAYBE/SKIP plus a contribution breakdown and plain-language reasons.
- Capture the user's final label and disagreement reason.
- Calibrate against at least 30 real alerts reviewed by the user.

**Exit criteria / go-no-go gate:**

- Every score is reproducible from a profile version and normalized inputs.
- The calibration report measures elimination rate, false negatives, false positives, and common missing fields.
- Continue to the dashboard if the system safely eliminates approximately 50% of page opens with no more than approximately 5% false negatives.
- If it fails, revise alert targeting/rules once; otherwise stop or reposition the product rather than overbuilding it.

**Candidate BDD features:**

- `Reject a job below the minimum acceptable rate`
- `Keep incomplete but promising jobs for manual review`
- `Explain every scoring contribution`
- `Reproduce a decision using its scoring-profile version`

### Phase 4 — Accessible Review Dashboard and Manual Enrichment

**Implementation status:** Complete. The protected read journey, enrichment/feedback, guarded synthetic demo entry, and Chromium acceptance passed protected CI on merged commit `906586d`. Candidate `65bf4ea` was deployed as an isolated synthetic demo and passed the approved target-host smoke on 2026-09-22. Phase 3's failed feasibility targets remain historical evidence and are not converted into a GO claim.

**Hypothesis:** A compact review queue makes the remaining human work faster and provides a credible portfolio demonstration.

**Capabilities:**

- Display ranked alerts with filters, status, reasons, missing-data warnings, and the canonical marketplace link.
- Provide keyboard-accessible triage actions and responsive layouts.
- Accept a manually pasted full description and re-evaluate the job without automated marketplace access.
- Capture final user decisions, notes, and outcome feedback.
- Enforce workspace ownership throughout the UI and API.

**Exit criteria:**

- The primary review journey passes automated BDD/E2E scenarios and a manual accessibility sweep.
- The user can process a seeded review queue without credentials or external services.
- Full-description enrichment visibly distinguishes user-supplied data from email-derived data.
- Cross-workspace access is denied and covered by integration tests.

**Candidate BDD features:**

- `Review the highest-ranked opportunities first`
- `Filter the queue by decision and missing data`
- `Re-evaluate a job after manual description enrichment`
- `Prevent access to another workspace's opportunities`

### Phase 5 — Portfolio Release and Compliant Extensibility

**Implementation status:** Complete. `v1.0.0` was published and verified on 2026-09-28. The project sheet records the separate public portfolio hosting move and acceptance on 2026-10-25. Phase 6 extends the completed release; its new runtime services require new evidence.

**Hypothesis:** Operational evidence and honest architectural boundaries make the project stronger than feature breadth alone.

**Capabilities:**

- Publish a sanitized demo dataset, installation guide, architecture overview, ADR index, threat model, and runbook.
- Add CI quality/security gates, dependency updates, SBOM generation, and release automation.
- Add observability for ingestion lag, parse failures, duplicate suppression, and scoring distributions.
- Demonstrate a second synthetic workspace and tenant isolation without exposing real alert content.
- Define a provider adapter contract for future sources that explicitly authorize programmatic access.

**Exit criteria:**

- A clean checkout can run tests and launch the demo using documented commands.
- CI and security gates are green; no real credentials, tracking tokens, addresses, or private email content remain in repository history.
- The release includes screenshots, a short architecture narrative, known limitations, and a rollback procedure.
- Version `1.0.0` is tagged only after the demo and deployment smoke tests pass.

### Phase 6: Live AI Evaluation & Reports (Planned)

**Status:** Scope accepted for planning on 2026-10-26; implementation and acceptance are pending.

**Hypothesis:** A visitor-triggered, real email-to-AI-to-report workflow makes the engineering demo more informative. It does not validate commercial usefulness or solve delayed job-alert discovery.

**Capabilities:**

- Manually fetch up to 20 latest eligible authored fictional alerts from a dedicated owner-controlled demo inbox over TLS. Keep the public demo, credentials, private mailbox and private data isolated; retain disabled private intake and do not introduce recurring public polling.
- Evaluate with a real AI API using Marcel’s approved, versioned prompt and sanitized public profile. Show APPLY/MAYBE/SKIP, reasons, supporting evidence and unknowns; label the profile clearly. Preserve existing deterministic scores, human decisions and historical calibration.
- Run bounded background work with visible progress, partial/failure states, idempotency, concurrency/request limits and an application-enforced hard daily spend cap covering retries. Treat message text as untrusted data and validate model output.
- Publish an on-screen synthetic report and downloadable HTML report with matching stored results, timestamps and provenance. Demonstrate email only through an operator-only send to the verified owner test inbox. The Upwork-facing public flow collects no visitor email addresses.

**Delivery slices:**

- 6.1 — Resolve the actual prompt/public profile, publishable corpus, provider/model and data handling, host execution mechanism, numeric limits and owner-approved budget; record focused ADRs.
- 6.2 — Implement bounded demo intake, durable run/item state and a replaceable AI adapter with schema validation, idempotency, retries, isolation and cost enforcement.
- 6.3 — Add accessible progress, explanations and report download; preserve the existing review workflow and test refresh, stale responses, errors and expiry.
- 6.4 — Verify operator-only owner delivery and a budgeted live staging run, then follow the existing candidate, smoke, rollback/disable and release process.

**Local Slice 6.2 checkpoint - 2026-10-28:** feature-owned backend state/intake/provider/queue/accounting and session-owned JSON trigger/status surfaces are implemented and tested offline. **6.2 is not complete:** a proven whole-provider-input token bound including schema/framing is unavailable; production dispatch fails closed. Do not progress to 6.3 or enable live processing. See [actual implementation evidence and blocker](runbooks/phase6-readiness.md#local-slice-62-implementation-checkpoint---2026-10-28) and [backend scenario mapping](features/live_ai_evaluation_reports.feature). Slices 6.3 and 6.4 remain pending.

**Token-count continuation - 2026-10-29:** current official documentation describes exact server-side counting including schema/framing. Owner-approved bounded integration and offline tests now exist, with unchanged caps and a default-disabled policy gate. At most 20 authenticated counting-only corpus requests are conditionally approved after explicit zero-charge and acceptable data-handling evidence; that condition is unmet, so none was performed and production remains blocked. Fresh PHPUnit/coverage/static/build/browser checks pass; Composer audit reports an unchanged locked dependency advisory and secret scanning is outstanding. See [actual validation and remaining gates](runbooks/phase6-readiness.md) and [counting guarantee/limitations](adr/ADR-010-demo-ai-provider-and-budget.md#whole-input-counting-approval-gate). **6.2 remains incomplete and 6.3 blocked.**

**Provider-policy follow-up - 2026-10-29:** public official sources confirm API-wide no-training/default abuse-monitoring rules but do not explicitly establish zero-charge counting or endpoint-specific application-state/cache retention. No authenticated count was attempted; the guard/defaults are unchanged. Obtain the provider clarification drafted in the [readiness continuation](runbooks/phase6-readiness.md#slice-62-provider-policy-follow-up---2026-10-29) before consuming the conditional 20-request allowance. Suites/audits were not rerun in this documentation-only follow-up; the previously reported dependency advisory and unavailable Gitleaks remain outstanding. **6.2 cannot close and 6.3 has not begun.**

**Later single-count experiment - 2026-10-29:** the foundation and owner-approved MIME patch were merged with green CI. Under a new, explicitly accepted one-request exception for unknown charges/retention, DEMO-001 returned HTTP 200 and **1,116 input tokens** for its actual complete request; no inference or production activation occurred. [Evidence and canonical hashes](runbooks/phase6-readiness.md#owner-authorized-single-counting-experiment---2026-10-29) cover **1 of 20** requests only. The exception is exhausted; 19 corpus requests, billing and counting-specific retention remain unverified. Defaults, empty policy-evidence hash and production guard are unchanged. **6.2 remains incomplete; 6.3 remains blocked.**

**Remaining-count evidence - 2026-10-29:** a separately authorized 19-request extension measured DEMO-002 through DEMO-020 without recounting DEMO-001, retries or inference. **20/20 frozen actual requests now pass 8,000**, minimum **1,086**, maximum **1,127**; [complete counts, hashes, timestamps and request IDs](runbooks/phase6-readiness.md#owner-authorized-remaining-19-counts---2026-10-29) are recorded. The owner-reported USD 10.00 balance/no visible decrease after DEMO-001 is an observation, not verified free counting. Both experimental allowances are consumed; billing/endpoint retention and production policy remain unverified. No policy-evidence hash, persistent enablement, application/host change or shared-document sync occurred. **Only the frozen-corpus measurement gap is closed; 6.2 remains incomplete and 6.3 blocked.**

**Subsequent owner risk decision - 2026-10-29:** Marcel approved proceeding despite unknown counting charges/endpoint retention, reporting **USD 10.00 balance with automatic recharge disabled**, with excessive charges to be scrutinized. [Decision record](adr/ADR-010-demo-ai-provider-and-budget.md#owner-decision-proceed-with-accepted-counting-policy-uncertainty---2026-10-29). Provider confirmation is no longer an owner-required prerequisite; uncertainty is accepted, not resolved or represented as free counting. This documentation-only decision has not implemented an accepted-risk path or changed guards/accounting, the USD 1.00/day limit, production settings or Phase 6 slice status. Balance/no recharge is operator-reported, not a verified hard cap.

**Accepted-risk implementation - 2026-10-29:** the owner-approved path is now implemented offline with separate verified-policy/owner-risk modes, exact protected consent reference, frozen/rechecked policy identity and persistent **unknown counting fees**, distinct from inference-only cost settlement. Counting failures do not retry/infer; inference uncertainty/reconciliation safeguards remain. The unchanged USD 1.00/day ledger bounds modeled inference, not unknown counting fees or a guaranteed total-provider-charge ceiling. All other caps/default-disabled switches remain unchanged. **108 demo regressions / 894 assertions**, PHPStan and Pint passed; [evidence and configuration boundaries](runbooks/phase6-readiness.md#accepted-risk-backend-implementation---2026-10-29). **6.2 is ready for owner acceptance review under the recorded exception; 6.3 and production activation have not begun.** No new provider/SSH calls or shared-document sync occurred.

**Slice 6.1 checkpoint — 2026-10-28: READY for bounded, offline-first Slice 6.2 implementation, not live activation.** The initial 2026-10-27 checkpoint was NOT READY; subsequent host checks and owner confirmations closed the planning gates. Both shared originals were retrieved again read-only on 2026-10-28 and matched the original baseline without replacing local edits. Approved: public v2 rubric/profile/prompt, OpenAI `gpt-6-luna` (owner-approved replacement; no reasoning/tools, `store=false`, default 30-minute caching), global USD 1.00/day at midnight UTC including retries/uncertainty, 20 items, one active/two queued runs, one provider request at a time / maximum 30 per minute, 30-minute run/queue deadline and seven-day reports. Revised 20-item estimates: USD 0.009 typical, 0.0688 maximum with retries, 0.075680 reserved including cache writes and 10% margin; not measured usage. The 20 fictional messages (`authored-demo-v2`) are approved for future publication, not published or sent now. The bounded scheduled database-worker design has sufficient host supporting evidence for implementation, not operating-worker acceptance.

See the [readiness record and residual acceptance boundaries](runbooks/phase6-readiness.md), [authored-input ADR](adr/ADR-009-public-ai-demo-inputs.md), [provider/budget ADR](adr/ADR-010-demo-ai-provider-and-budget.md) and [execution/state ADR](adr/ADR-011-bounded-demo-execution.md). VERIFIED: public-host runtime/database configuration, bounded process/overlap controls, nearly three-hour unattended cron continuity, HTTPS/IMAP TLS egress, staged mailbox authentication, protected recipient syntax/separation and provider model-metadata access. OPERATOR-REPORTED: exclusive demo mailbox use, control/allowlisting of the staged owner recipient, dedicated API project, billing/Responses permission and no data-sharing opt-in with standard retention accepted. No console inspection, message retrieval, paid inference, delivery, application feature or deployment acceptance is claimed. Temporary cron probes are removed; runtime configuration remains unchanged.

**Exit criteria:**

- Deterministic tests cover successful and partial runs, empty/duplicate/malicious inputs, malformed AI output, provider failures, isolation and concurrent cap enforcement. CI uses controlled adapters and makes no paid or mailbox calls.
- A separately recorded live run verifies actual demo-mail retrieval, the selected AI provider, report consistency, one owner email and measured usage/cost. Cached output, adapter fakes and historical tests are never presented as new live evidence.
- Browser/manual checks verify accessibility, clear synthetic/profile labels, progress and failure states; the private pipeline is unchanged and the seeded demo remains usable with live mode disabled.
- The prompt/profile, provider/model, retention, numeric limits and runbook are documented. New feature acceptance and release evidence are complete; no evaluation-accuracy or commercial-effectiveness claim is inferred.

**Deferred boundaries:** No marketplace scraping or official API integration, visitor-specific profiles, visitor prompt uploads, real-alert republication or public visitor-email sending. Genuine private AI evaluation is a separate operator task. Visitor delivery requires a later permitted-context, recipient-verification, consent, retention and abuse-control decision; neither is a Phase 6 release prerequisite.

**Candidate BDD feature:** `docs/features/live_ai_evaluation_reports.feature`, mapped to PHPUnit/MariaDB, Playwright and explicit live operator checks. Detailed contracts and seven-day generated-report retention are in the [Phase 6 project sheet](project_sheet.md#phase-6-live-ai-evaluation--reports).

### Optional Post-MVP Extension — Reusable Tester Skill (Deferred)

**Hypothesis:** Packaging the Learning & Adaptation quality review as a reusable Tester Skill will demonstrate the project's quality-engineering approach and make phase audits repeatable without adding AI to the application runtime.

This extension is not part of the MVP or its Definition of Done. Specify it only after the functioning MVP and its baseline CI evidence exist.

**Candidate capabilities:**

- Consume the selected phase's as-built `project_sheet.md`, associated `.feature` files and ADRs, the implementation diff, and test/CI results.
- Trace acceptance criteria to automated and manual evidence, identify missing coverage, and inspect applicable security, privacy, tenancy, accessibility, performance, and regression risks.
- Produce severity-ranked, reproducible findings, an evidence-gap report, and a phase Definition-of-Done verdict without inventing results or modifying production code.
- Use this platform as the reference demonstration while keeping the skill reusable across projects following the same Scrum-XP workflow.

**Entry and exit criteria:**

- Entry: the MVP has a seeded demo, documented acceptance scenarios, runnable test suites, and a known-green CI baseline.
- Exit: the skill detects a curated set of defects in an isolated test branch or fixture set, does not report unexecuted checks as passed, and generates a review report traceable to the supplied specifications and evidence.
- Installation, invocation, limitations, and the boundary between Tester Skill findings and tactical implementation are documented.

## Top Risks and Mitigations

| Risk                                                     | Impact                             | Mitigation / validation                                                                                               |
| -------------------------------------------------------- | ---------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Alerts omit full requirements and marketplace metrics    | Incorrect classification           | Fail uncertain to MAYBE; manual enrichment; measure false negatives before UI investment                              |
| Upwork changes the email template                        | Parser failures or silent bad data | Plain-text-first parser, sanitized fixture corpus, template fingerprint, quarantine, parse-failure alert              |
| Hosting IMAP or cron behavior is limited                 | Delayed or failed ingestion        | Phase 2 connectivity spike; idempotent scheduled command; local `.eml` import fallback                                |
| Rule tuning hides suitable work                          | Lost opportunities                 | Explainable scoring, versioned profiles, conservative thresholds, user labels, explicit go/no-go metrics              |
| Workspace scoping or stored email data leaks information | Privacy/security failure           | Mandatory ownership keys, policies/global scopes, isolation tests, minimal retention, secret and fixture sanitization |

### Phase 6 risks and controls

AI errors or injected email instructions: allowlisted inputs, no model tools, structured output validation and visible unknowns. Public-call abuse or runaway cost: bounded jobs, idempotency and atomic budget reservations. Privacy/content leakage: authored demo data and server-selected isolated sources. Provider outage or mail failure: honest partial/failure status, bounded retries and the retained seeded demo. Delayed-alert usefulness remains unproven.

## Assumptions to Validate

- The provider permits scheduled commands and outbound IMAP over TLS.
- A dedicated mailbox or folder can isolate job alerts from personal mail.
- Supported alerts continue to include a UTF-8 `text/plain` alternative and a stable canonical job identifier.
- The user can label at least 30 alerts with a final suitability decision for calibration.
- MariaDB 11.4 and required PHP extensions are available in both staging and production.
- Email processing of the user's own received alerts remains within applicable service terms; no marketplace page automation is introduced.
- Phase 6 entry decisions: approve the actual prompt/public profile and publishable corpus; select the provider/model and data handling; verify outbound AI access and bounded background execution on the demo host.
- Phase 6 activation requires concrete request/concurrency/token/time limits, an owner-approved hard daily budget, a verified owner test recipient and a documented disable/recovery path. Do not interpret this plan as completed verification.

## ADR Candidates

- **ADR-001:** Laravel modular monolith and the boundary of the Node.js toolchain
- **ADR-002:** MariaDB shared-schema, workspace-scoped tenancy
- **ADR-003:** Plain-text-first MIME parsing and template-drift handling
- **ADR-004:** Scheduled IMAP polling versus a Node mail worker
- **ADR-005:** Deterministic scoring before any optional AI-assisted evaluation
- **ADR-006:** Minimal raw-email retention and fixture sanitization policy
- Phase 6 planning records: [ADR-009](adr/ADR-009-public-ai-demo-inputs.md) covers authored inputs, public profile and report/delivery boundaries; [ADR-010](adr/ADR-010-demo-ai-provider-and-budget.md) covers the provider/data contract and spend enforcement; [ADR-011](adr/ADR-011-bounded-demo-execution.md) covers conditional execution and durable state. Each distinguishes approved planning decisions from conditional execution and pending evidence. Broader visitor delivery remains deferred; ADR-006 remains reserved.

## Phase Definition of Done

A phase is complete only when its acceptance scenarios pass, unit/integration tests and static analysis are green, security/privacy notes are addressed, relevant ADRs are recorded, and `project_sheet.md` reflects the as-built system. Accessibility and manual verification are mandatory for phases containing UI.

## Immediate Next Decision

The owner's 2026-10-28 go-ahead produced the [local 6.2 backend foundation](runbooks/phase6-readiness.md#local-slice-62-implementation-checkpoint---2026-10-28). Close its material whole-input token-proof blocker before marking 6.2 complete or progressing to 6.3. Do not bypass the rejecting production token-bound binding, change dependencies/model/pricing without approval, or activate intake, paid AI, delivery or permanent scheduling automatically. Phase 6 is not accepted; the existing public demo and Phases 1-5 remain complete.

**Historical checkpoint:** Phase 4 target-host acceptance passed on deployed candidate `65bf4ea`. The completed Phase 3 calibration remains historical evidence: 30 reviewed imports, 3.33% machine skip rate, 9.09% false-negative rate, and `targets_met=false`. No calibration-based GO is claimed.

Preserve the prior Phase 2 soak and deployment records as historical evidence for their named commits. The polling-deadline correction closed through protected CI run `34269173609` and target-host verification of merge commit `161c97d`, but those checks do not prove an absolute database runtime bound.

The optional Tester Skill remains deferred and must not expand the Phase 1 `project_sheet.md` or MVP scope.
