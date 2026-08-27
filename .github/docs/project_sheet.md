# Project Sheet — Phase 1: Fixture-Driven Email Normalization

**Document role:** Living, as-built technical specification  
**Current status:** Draft for implementation; no application code exists yet  
**Roadmap source:** `docs/PROJECT_ROADMAP.md`, Phase 1 only  
**Last updated:** 2026-08-25

> This context intentionally excludes implementation detail for Phases 2–5. After Phase 1 is implemented and audited, update this document to match the final code exactly.

## Action

Implement the smallest offline vertical slice that converts a supported raw Upwork job-alert `.eml` message into a normalized, workspace-owned opportunity record, without contacting Gmail, Upwork, or any other network service.

## Business Value

Prove that the alert structure is dependable enough to support automated triage before investing in mailbox integration, scoring, or a dashboard. Phase 1 creates the trustworthy input boundary on which all later behavior depends.

## Observed Input Contract

The three inspected original alerts share these characteristics:

- Sender display name `Upwork Notification` and address `donotreply@upwork.com`.
- Subject begins `New job alert:` and may contain HTML entities or truncation.
- Root MIME type `multipart/alternative`.
- UTF-8, quoted-printable `text/plain` and `text/html` alternatives.
- Plain-text body contains title/link, contract terms, posting date, excerpt, visible skills, client summary, and a `View job details` link.
- Canonical job identity appears in an Upwork URL path as `/jobs/~<numeric-id>`.
- Rate may be emitted as `$0.00 - $0.00`; this means unavailable/unknown, not genuinely free work.
- Descriptions, titles, and skills may be truncated; skill lists may end with `+N more`.

The parser must use the `text/plain` alternative. HTML is neither rendered nor executed in Phase 1.

## Phase Boundary

### Included

- Laravel project foundation required by this slice.
- Safe MIME decoding of a local raw `.eml` string/file.
- Parsing and normalization of the observed hourly-alert template.
- Workspace ownership, persistence, deduplication, and quarantine metadata.
- A local Artisan import command.
- Sanitized synthetic fixtures, unit tests, database integration tests, and the Phase 1 BDD specification.

### Excluded

- Gmail, IMAP, OAuth, mailbox folders, polling, cron, queues, or background workers.
- Fixed-price alerts until a real sample is available.
- Scoring, APPLY/MAYBE/SKIP decisions, user-configurable rules, or AI evaluation.
- Authentication UI, dashboards, full-description paste, and proposal workflows.
- Upwork page requests, scraping, Cloudflare handling, browser automation, or API integration.

## Technical Decisions

- Laravel 13, PHP 8.4, MariaDB 11.4.
- Node.js is not needed at runtime in Phase 1.
- Use `zbateson/mail-mime-parser:^4.0`; `composer.lock` must resolve version 4.0.3 or newer within major version 4 because earlier releases lacked current resource-limit/security fixes.
- Prefer the decoded plain-text MIME part; absence of plain text is an unsupported template.
- Store external job identifiers as strings, never integers; current IDs exceed 64-bit integer range.
- Store no production raw email body, HTML body, recipient address, tracking token, or full header set.
- Use string-backed PHP enums and database strings rather than database-native enums.
- Keep tenancy explicit: application operations receive a workspace ID, and database uniqueness is workspace-scoped.

## Normalized Data Contract

`App\Domain\Opportunities\Data\ParsedOpportunity` is an immutable DTO with the following fields:

| Field                    | PHP type              | Required | Normalization rule                                                                  |
| ------------------------ | --------------------- | -------- | ----------------------------------------------------------------------------------- |
| `provider`               | `OpportunityProvider` | Yes      | `upwork_email`                                                                      |
| `sourceMessageId`        | `string`              | Yes      | Trim angle brackets and whitespace; maximum 255 characters                          |
| `externalJobId`          | `string`              | Yes      | Digits captured from `/jobs/~<id>`; maximum 64 characters                           |
| `canonicalUrl`           | `string`              | Yes      | Exactly `https://www.upwork.com/jobs/~<id>`; discard query and fragment             |
| `title`                  | `string`              | Yes      | Body title preferred; decode entities, normalize whitespace, maximum 255 characters |
| `contractType`           | `ContractType`        | Yes      | `hourly` in Phase 1                                                                 |
| `hourlyMin`              | `?string`             | No       | Decimal string with two places; `$0–$0` becomes `null`                              |
| `hourlyMax`              | `?string`             | No       | Decimal string with two places; `$0–$0` becomes `null`                              |
| `currency`               | `string`              | Yes      | `USD` for the observed template                                                     |
| `estimatedDuration`      | `?string`             | No       | Normalized display label; maximum 100 characters                                    |
| `postedOn`               | `?CarbonImmutable`    | No       | Infer year from message date and choose the nearest non-future calendar date        |
| `excerpt`                | `?string`             | No       | Plain text only; normalize whitespace; preserve the fact it is truncated            |
| `skills`                 | `list<string>`        | Yes      | Visible skills, decoded and deduplicated case-insensitively in source order         |
| `hiddenSkillCount`       | `int`                 | Yes      | Parse `+N more`; default `0`                                                        |
| `paymentVerified`        | `?bool`               | No       | `true` for `Payment verified`; otherwise `null` unless explicitly unverified        |
| `clientRating`           | `?string`             | No       | Decimal string, range `0.00–5.00`                                                   |
| `clientSpendUsd`         | `?string`             | No       | Expand rounded suffixes, e.g. `$79K` → `79000.00`                                   |
| `clientSpendApproximate` | `bool`                | Yes      | `true` when source uses `K`, `M`, or another rounded suffix                         |
| `clientCountry`          | `?string`             | No       | Decoded display name; maximum 100 characters                                        |
| `templateFingerprint`    | `string`              | Yes      | Stable parser-owned template identifier, initially `upwork-alert-hourly-v1`         |

Money and rating values remain decimal strings inside the parser and DTO to avoid binary floating-point errors. Eloquent casts them to database decimals at the persistence boundary.

## Result and Error Contract

`App\Application\Opportunities\Data\ImportResult` contains:

| Field           | Type                   | Meaning                                                 |
| --------------- | ---------------------- | ------------------------------------------------------- |
| `status`        | `EmailImportStatus`    | `imported`, `updated`, `duplicate`, or `quarantined`    |
| `opportunityId` | `?string`              | ULID of the persisted opportunity when available        |
| `externalJobId` | `?string`              | Safe external identifier for CLI output and diagnostics |
| `errorCode`     | `?EmailParseErrorCode` | Stable code; never a raw parser exception message       |

Stable error codes:

- `email_too_large`
- `mime_parse_failed`
- `missing_message_id`
- `unsupported_sender`
- `unsupported_subject`
- `missing_plain_text`
- `unsupported_contract_type`
- `missing_job_id`
- `invalid_job_url`
- `missing_title`
- `malformed_terms`
- `unsupported_template`

## Persistence Model

### `workspaces`

| Column     | Type / constraints |
| ---------- | ------------------ |
| `id`       | ULID primary key   |
| `name`     | `varchar(100)`     |
| timestamps | Laravel timestamps |

### `opportunities`

| Column                     | Type / constraints                               |
| -------------------------- | ------------------------------------------------ |
| `id`                       | ULID primary key                                 |
| `workspace_id`             | ULID foreign key to `workspaces`, cascade delete |
| `provider`                 | `varchar(32)`                                    |
| `external_id`              | `varchar(64)`                                    |
| `canonical_url`            | `varchar(500)`                                   |
| `title`                    | `varchar(255)`                                   |
| `contract_type`            | `varchar(32)`                                    |
| `hourly_min`, `hourly_max` | nullable `decimal(10,2)`                         |
| `currency`                 | `char(3)`                                        |
| `estimated_duration`       | nullable `varchar(100)`                          |
| `posted_on`                | nullable `date`                                  |
| `excerpt`                  | nullable `text`                                  |
| `hidden_skill_count`       | unsigned small integer, default `0`              |
| `payment_verified`         | nullable boolean                                 |
| `client_rating`            | nullable `decimal(3,2)`                          |
| `client_spend_usd`         | nullable `decimal(14,2)`                         |
| `client_spend_approximate` | boolean, default `false`                         |
| `client_country`           | nullable `varchar(100)`                          |
| `source_template`          | `varchar(64)`                                    |
| timestamps                 | Laravel timestamps                               |

Unique constraint: `(workspace_id, provider, external_id)`.

### `opportunity_skills`

| Column           | Type / constraints                                  |
| ---------------- | --------------------------------------------------- |
| `id`             | ULID primary key                                    |
| `opportunity_id` | ULID foreign key to `opportunities`, cascade delete |
| `name`           | `varchar(100)`                                      |
| `position`       | unsigned small integer                              |

Unique constraint: `(opportunity_id, name)`.

### `email_imports`

| Column           | Type / constraints                                               |
| ---------------- | ---------------------------------------------------------------- |
| `id`             | ULID primary key                                                 |
| `workspace_id`   | ULID foreign key to `workspaces`, cascade delete                 |
| `opportunity_id` | nullable ULID foreign key to `opportunities`, set null on delete |
| `message_id`     | nullable `varchar(255)`                                          |
| `content_sha256` | fixed `char(64)`                                                 |
| `status`         | `varchar(32)`                                                    |
| `error_code`     | nullable `varchar(64)`                                           |
| `imported_at`    | timestamp                                                        |
| timestamps       | Laravel timestamps                                               |

Unique constraints: `(workspace_id, message_id)` and `(workspace_id, content_sha256)`. Multiple null `message_id` values are acceptable; the content hash remains the fallback idempotency key.

## Interfaces

```php
namespace App\Domain\Opportunities\Contracts;

use App\Domain\Opportunities\Data\ParsedOpportunity;

interface OpportunityEmailParser
{
    /** @throws \App\Domain\Opportunities\Exceptions\EmailParseException */
    public function parse(string $rawEmail): ParsedOpportunity;
}
```

```php
namespace App\Application\Opportunities;

use App\Application\Opportunities\Data\ImportResult;

final class ImportOpportunityEmail
{
    public function execute(string $workspaceId, string $rawEmail): ImportResult;
}
```

`UpworkJobAlertParser` implements the parser contract. `ImportOpportunityEmail` owns idempotency, transactions, upsert behavior, skill replacement, and quarantine metadata; the parser remains side-effect free.

## Ordered Task Plan

1. **Scaffold the supported runtime**
   - Create the Laravel 13 application and commit `composer.lock`.
   - Set PHP constraint compatible with 8.5.
   - Add `zbateson/mail-mime-parser:^4.0` and verify the locked version is at least 4.0.3.
   - Configure PHPUnit, Laravel Pint, Larastan/PHPStan, and test environment variables.

2. **Create sanitized fixture evidence**
   - Add `tests/Fixtures/Emails/upwork/hourly-client-success.eml`.
   - Add `tests/Fixtures/Emails/upwork/hourly-operations-coordinator.eml`.
   - Add `tests/Fixtures/Emails/upwork/hourly-unknown-rate.eml`.
   - Preserve MIME boundaries, quoted-printable encoding, and structural variability while replacing all names, addresses, job IDs, tracking parameters, client text, and message IDs with synthetic values.
   - Never commit the original uploaded messages or generated artifacts containing their real metadata.

3. **Add configuration and domain types**
   - `config/opportunity_sources.php`: maximum raw size `1_048_576` bytes, expected sender, subject prefix, allowed host, and template fingerprint.
   - `app/Domain/Opportunities/Contracts/OpportunityEmailParser.php`.
   - `app/Domain/Opportunities/Data/ParsedOpportunity.php`.
   - `app/Domain/Opportunities/Enums/OpportunityProvider.php`.
   - `app/Domain/Opportunities/Enums/ContractType.php`.
   - `app/Domain/Opportunities/Enums/EmailImportStatus.php`.
   - `app/Domain/Opportunities/Enums/EmailParseErrorCode.php`.
   - `app/Domain/Opportunities/Exceptions/EmailParseException.php`.

4. **Implement the pure parser**
   - `app/Infrastructure/Email/UpworkJobAlertParser.php`.
   - Reject an oversized raw message before MIME parsing.
   - Decode MIME with bounded parser settings and select `text/plain` only.
   - Validate message ID, sender, subject prefix, and supported hourly terms.
   - Parse the observed sections using anchored, readable patterns; avoid a single whole-message regular expression.
   - Extract the job ID only from an HTTPS URL whose host is exactly `www.upwork.com` and path matches `/jobs/~<digits>`.
   - Build the canonical URL without query parameters or fragments.
   - Normalize entities, whitespace, decimals, approximate spend suffixes, skill order, and posting date.
   - Throw only typed `EmailParseException` errors with stable codes.

5. **Create tenancy-ready persistence**
   - `database/migrations/2026_08_25_000001_create_workspaces_table.php`.
   - `database/migrations/2026_08_25_000002_create_opportunities_table.php`.
   - `database/migrations/2026_08_25_000003_create_opportunity_skills_table.php`.
   - `database/migrations/2026_08_25_000004_create_email_imports_table.php`.
   - `app/Models/Workspace.php`.
   - `app/Models/Opportunity.php`.
   - `app/Models/OpportunitySkill.php`.
   - `app/Models/EmailImport.php`.
   - Add factories for workspace and opportunity integration tests.

6. **Implement the import transaction**
   - `app/Application/Opportunities/Data/ImportResult.php`.
   - `app/Application/Opportunities/ImportOpportunityEmail.php`.
   - Calculate SHA-256 before parsing and check workspace-scoped idempotency keys.
   - Parse outside the database transaction; persist the normalized result inside one transaction.
   - Insert the first job, update an existing job received under a new message ID, and replace its visible skills atomically.
   - Record `duplicate` without changing the opportunity when message ID or content hash was already processed.
   - On a typed parse failure, store only hash, safe message ID when available, status, and error code; never store bodies or raw exception text.

7. **Expose a local-only command**
   - `app/Console/Commands/ImportOpportunityEmailCommand.php`.
   - Signature: `opportunity:import-email {path} {--workspace=}`.
   - Read a local file, call the application action, print status and safe identifiers only, and return a non-zero code for quarantine or invalid command input.
   - Do not expose an HTTP upload endpoint in Phase 1.

8. **Bind and document the slice**
   - Bind `OpportunityEmailParser` to `UpworkJobAlertParser` in `app/Providers/AppServiceProvider.php`.
   - Add Phase 1 usage, supported template, limitations, fixture provenance, and example command to `README.md`.
   - Add `specs/features/normalize_job_alert.feature` using the scenarios below.
   - Update this project sheet after implementation to reflect actual class signatures, migrations, and deviations.

## Parser Algorithm

1. Reject input larger than 1 MiB.
2. Parse MIME with configured resource limits.
3. Read and normalize `Message-ID`, `From`, `Subject`, `Date`, and `text/plain`.
4. Validate the sender and subject against configuration.
5. Locate the first valid job URL on the allowed host and derive the string external ID and canonical URL.
6. Identify the title/terms block, excerpt block, skills block, and client-summary line using section boundaries.
7. Normalize each field independently and validate required values.
8. Return the immutable DTO; perform no database, filesystem, HTTP, DNS, or logging side effect.

## Acceptance Scenarios

Target file: `specs/features/normalize_job_alert.feature`.

### Scenario: Parse a supported hourly job alert

Given a sanitized original Upwork hourly alert, when it is imported for a workspace, then one normalized opportunity and its visible skills are persisted with a tracking-free canonical URL.

### Scenario: Represent an unavailable hourly rate as unknown

Given an otherwise valid alert showing `$0.00 - $0.00`, when it is imported, then both hourly bounds are null and the job is not interpreted as zero-rate work.

### Scenario: Ignore a duplicate job alert

Given an alert already imported for a workspace, when the same message is imported again, then no second opportunity or skill records are created and the result is `duplicate`.

### Scenario: Quarantine an unsupported or malformed email

Given an oversized, wrong-sender, or structurally unsupported email, when import is attempted, then no opportunity is created and only a safe quarantine record with a stable error code is stored.

## Named Test Plan

### Parser unit tests

File: `tests/Unit/Infrastructure/Email/UpworkJobAlertParserTest.php`

- `it_parses_each_supported_hourly_fixture`
- `it_converts_a_zero_rate_range_to_unknown`
- `it_decodes_html_entities_and_normalizes_whitespace`
- `it_extracts_visible_skills_and_the_hidden_skill_count`
- `it_expands_rounded_client_spend_without_claiming_precision`
- `it_infers_the_nearest_non_future_posting_date`
- `it_strips_all_query_parameters_and_fragments_from_the_job_url`
- `it_rejects_a_non_https_or_non_allowlisted_job_url`
- `it_rejects_an_unexpected_sender`
- `it_rejects_a_missing_plain_text_part`
- `it_rejects_an_oversized_email_before_mime_parsing`
- `it_rejects_a_message_without_a_job_identifier`
- `it_returns_only_stable_error_codes`

### Import integration tests

File: `tests/Feature/Application/ImportOpportunityEmailTest.php`

- `it_persists_an_opportunity_and_ordered_skills_for_a_workspace`
- `it_does_not_duplicate_the_same_message_or_content_hash`
- `it_updates_the_same_job_received_under_a_new_message_id`
- `it_allows_the_same_external_job_id_in_different_workspaces`
- `it_quarantines_invalid_input_without_storing_raw_content`
- `it_rolls_back_partial_opportunity_and_skill_writes`
- `it_never_persists_tracking_parameters_or_recipient_addresses`

### Command tests

File: `tests/Feature/Console/ImportOpportunityEmailCommandTest.php`

- `it_imports_a_local_eml_fixture_for_the_selected_workspace`
- `it_returns_a_non_zero_exit_code_for_quarantined_input`
- `it_does_not_print_email_bodies_headers_or_tracking_tokens`

### Database contract tests

File: `tests/Feature/Database/OpportunitySchemaTest.php`

- `it_enforces_workspace_scoped_opportunity_uniqueness`
- `it_enforces_workspace_scoped_message_and_hash_idempotency`
- `it_cascades_workspace_deletion_without_cross_workspace_effects`

## Accessibility

Not applicable to Phase 1 because there is no user interface. CLI output must still be concise, readable, and must not rely on color alone to communicate success or failure.

## Performance and Reliability Notes

- Hard raw-message limit: 1 MiB, enforced before MIME parsing.
- Target parse time: under 100 ms per fixture on the CI runner; this is a regression indicator, not a strict production SLO.
- The parser must not perform catastrophic-backtracking regexes; patterns are anchored to section lines and tested with malformed long input.
- Database writes occur in one transaction with unique constraints as the final concurrency guard.
- Import is safe under at-least-once delivery even though mailbox polling is outside Phase 1.
- Parser dependency limits must remain enabled; do not relax nesting, header-count, or header-size limits without an ADR and adversarial tests.

## Security and Privacy Notes

- Treat every email and every field inside it as untrusted input.
- Do not fetch remote images, links, stylesheets, scripts, or job pages.
- Do not render the HTML alternative.
- Permit only the exact HTTPS host and job-path pattern when creating canonical links.
- Never use a display name as proof of sender authenticity. Phase 1 validates the From address for template selection; DKIM/SPF verification belongs to the trusted mailbox boundary in Phase 2.
- Never log or persist raw email, recipient data, authentication headers, `frkscc`, `email_uid`, UTM values, or other query parameters.
- Synthetic fixtures must contain reserved example addresses and fake identifiers only.
- Sanitize exception handling so third-party parser messages cannot reach CLI output or database diagnostics.
- Run dependency audit and secret scanning in CI; keep the MIME parser at a release containing current resource-limit fixes.

## Assumptions and Open Checks

- Composer and the required `mbstring`/`iconv` extensions are available in development and deployment environments.
- MariaDB 11.4 accepts the selected ULID foreign keys, decimal columns, and composite unique indexes under the chosen charset.
- The three samples are sufficient for the first hourly-template version; any fixed-price alert creates a new failing fixture and specification change.
- Exact hosting cron and IMAP behavior remain intentionally untested until Phase 2.

## Phase 1 Definition of Done

- Four BDD scenarios pass.
- All named unit, integration, command, and schema tests pass on MariaDB 11.4.
- Pint, Larastan/PHPStan, dependency audit, and secret scan are green.
- Parser/domain coverage is at least 90%; overall coverage is at least 80%.
- Sanitized fixtures contain no real recipient addresses, tracking tokens, or private headers.
- No code path performs an external request.
- Relevant ADRs are recorded for any implementation deviation.
- This document is updated from draft to an accurate as-built description.
