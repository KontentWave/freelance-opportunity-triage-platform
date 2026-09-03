# Project Roadmap — Freelance Opportunity Triage Platform

**Status:** Vision approved; Phase 1 completed with clean CI evidence; Phase 2 local implementation complete and awaiting production verification; optional Tester Skill deferred until after MVP
**Primary user:** An independent freelancer reviewing opportunities from authorized job-alert emails  
**Working title:** To be decided; do not use Upwork trademarks in product branding

## Vision

Build a portfolio-quality personal tool that converts job-alert emails delivered to the user's own mailbox into an explainable, prioritized review queue. The platform should reduce low-value manual browsing while leaving all marketplace access, final judgment, and proposal submission under human control.

The first source is Upwork Freelancer Plus email alerts, but the design should remain provider-agnostic. A source adapter normalizes each alert into a common opportunity model; deterministic rules then classify it as **APPLY**, **MAYBE**, or **SKIP**, with reasons that the user can inspect and refine.

## Problem and Value Hypothesis

Job alerts contain enough summary and client-economics data to reject many unsuitable opportunities without opening the full marketplace listing. If the system can safely eliminate at least half of manual opens while rarely hiding a genuinely suitable job, it provides meaningful value even without marketplace API access.

This is a hypothesis to validate, not an assumption to defend. Phase 3 includes an explicit go/no-go calibration checkpoint.

## Product Principles

- **Compliance boundary:** Process emails the user is authorized to receive. Do not scrape marketplace pages, bypass access controls, automate proposals, or imitate human browsing.
- **Human-in-the-loop:** Automation triages; the user reviews shortlisted jobs and submits proposals manually.
- **Explainable first:** Every classification must show the rules, inputs, contributions, and missing data behind it.
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

## Technical Baseline

- **Backend:** Laravel 13 on PHP 8.4
- **Database:** MariaDB 11.4
- **Frontend:** Vue with Vite; Node.js 22.23 for the frontend toolchain
- **Deployment:** Provider-hosted web application with scheduled execution; exact cron and outbound IMAP capabilities must be verified
- **Email intake:** IMAP over TLS from a dedicated mailbox or dedicated folder; credentials remain environment secrets
- **Tenancy model:** Shared schema with mandatory `workspace_id` ownership and tested isolation

Node.js is not a separate production service by default. A Node-based mail worker remains an ADR option only if the hosting environment or PHP mail tooling makes it materially safer or simpler.

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

### Phase 2 — Secure Scheduled Mailbox Intake

**Hypothesis:** The hosting provider can fetch new alerts securely and reliably without a permanent background worker.

**Capabilities:**

- Connect to a dedicated IMAP mailbox/folder over TLS using environment-managed credentials.
- Fetch only candidate alert messages and submit them to the Phase 1 normalizer.
- Use at-least-once processing with idempotency, bounded retries, and failure quarantine.
- Record operational metadata without storing unnecessary mailbox headers or credentials.
- Run through the provider's scheduler/cron and expose a health result.

**Exit criteria:**

- Hosting IMAP endpoint, TLS mode, and cron interval are verified.
- A 24-hour staging soak imports alerts without duplicates or message loss.
- Temporary IMAP failures recover automatically; permanent failures are visible and actionable.
- Production secrets are absent from source control, logs, fixtures, and screenshots.

**Candidate BDD features:**

- `Import a newly received alert from the dedicated mailbox`
- `Retry a temporary mailbox failure without duplicating jobs`
- `Quarantine an unsupported message`

### Phase 3 — Explainable Triage and Feasibility Gate

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

## Assumptions to Validate

- The provider permits scheduled commands and outbound IMAP over TLS.
- A dedicated mailbox or folder can isolate job alerts from personal mail.
- Supported alerts continue to include a UTF-8 `text/plain` alternative and a stable canonical job identifier.
- The user can label at least 30 alerts with a final suitability decision for calibration.
- MariaDB 11.4 and required PHP extensions are available in both staging and production.
- Email processing of the user's own received alerts remains within applicable service terms; no marketplace page automation is introduced.

## ADR Candidates

- **ADR-001:** Laravel modular monolith and the boundary of the Node.js toolchain
- **ADR-002:** MariaDB shared-schema, workspace-scoped tenancy
- **ADR-003:** Plain-text-first MIME parsing and template-drift handling
- **ADR-004:** Scheduled IMAP polling versus a Node mail worker
- **ADR-005:** Deterministic scoring before any optional AI-assisted evaluation
- **ADR-006:** Minimal raw-email retention and fixture sanitization policy

## Phase Definition of Done

A phase is complete only when its acceptance scenarios pass, unit/integration tests and static analysis are green, security/privacy notes are addressed, relevant ADRs are recorded, and `project_sheet.md` reflects the as-built system. Accessibility and manual verification are mandatory for phases containing UI.

## Immediate Next Decision

Define the Phase 2 specification before additional implementation work. Keep `.github/docs/project_sheet.md` scoped to the completed Phase 1 system until a Phase 2 spec is explicitly approved.

Revisit this roadmap after the Phase 2 specification is agreed; do not expose later-phase implementation detail to the tactical coding context.

The optional Tester Skill remains deferred and must not expand the Phase 1 `project_sheet.md` or MVP scope.
