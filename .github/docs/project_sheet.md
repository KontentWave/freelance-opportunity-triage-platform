# Project Sheet — Phase 1: Fixture-Driven Email Normalization

**Document role:** As-built Phase 1 specification  
**Current status:** Phase 1 application slice implemented; MariaDB-only validation and CI added in-repo  
**Last updated:** 2026-08-29

## Phase 1 Outcome

This repository implements an offline-only import slice for sanitized Upwork job-alert `.eml` fixtures. A local raw email file is parsed from its plain-text MIME part, normalized into a workspace-owned opportunity, and persisted with workspace-scoped idempotency and quarantine metadata.

Phase 1 does not contact Gmail, IMAP, Upwork, or any other external service.

## Included

- Laravel 13 application code on PHP 8.4.
- Local `.eml` fixture parsing with `zbateson/mail-mime-parser` 4.0.3.
- Upwork hourly-alert normalization from the observed plain-text template.
- Workspace-scoped opportunity persistence, skill replacement, import idempotency, and quarantine records.
- Local Artisan command `opportunity:import-email {path} {--workspace=}`.
- PHPUnit coverage for parser, import action, command behavior, and schema constraints.
- Gherkin documentation in `.github/docs/features/normalize_job_alert.feature`.

## Explicitly Excluded

- Gmail, IMAP, OAuth, mailbox polling, queues, schedules, or background ingestion.
- Fixed-price parsing, HTTP enrichment, dashboards, scoring, or Phase 2+ workflows.
- Persistence of raw email bodies, recipient addresses, tracking tokens, or full headers.

## Implemented Artifacts

### Core application classes

- `App\Infrastructure\Email\UpworkJobAlertParser`
- `App\Application\Opportunities\ImportOpportunityEmail`
- `App\Application\Opportunities\Data\ImportResult`
- `App\Domain\Opportunities\Contracts\OpportunityEmailParser`
- `App\Domain\Opportunities\Data\ParsedOpportunity`
- `App\Domain\Opportunities\Enums\ContractType`
- `App\Domain\Opportunities\Enums\EmailImportStatus`
- `App\Domain\Opportunities\Enums\EmailParseErrorCode`
- `App\Domain\Opportunities\Enums\OpportunityProvider`
- `App\Domain\Opportunities\Exceptions\EmailParseException`
- `App\Console\Commands\ImportOpportunityEmailCommand`

### Persistence

- `workspaces`
- `opportunities`
- `opportunity_skills`
- `email_imports`

Migrations live in `database/migrations` with timestamps `2026_08_27_145314` through `2026_08_27_145321`.

### Fixtures

Sanitized fixtures live under `tests/Fixtures/Emails/upwork`:

- `hourly-client-success.eml`
- `hourly-operations-coordinator.eml`
- `hourly-unknown-rate.eml`

These fixtures intentionally preserve MIME structure and may include synthetic recipient addresses, synthetic message IDs, and synthetic tracking-like query values so tests can prove they are never persisted or echoed back from the import workflow.

## Supported Input Contract

The current parser supports the observed Upwork hourly email template only.

Required characteristics:

- `From` address must exactly match `donotreply@upwork.com`.
- Subject must begin with `New job alert:`.
- A non-empty `text/plain` MIME part must be present.
- The plain-text body must contain at least one HTTPS Upwork job URL on `www.upwork.com` whose path matches `/jobs/~<digits>`.
- Hourly terms must match `Hourly: $<min> - $<max>`.

Implemented normalization rules:

- Raw messages larger than `1_048_576` bytes are rejected before MIME parsing.
- `Message-ID` is trimmed of angle brackets and whitespace and capped at 255 characters.
- The canonical job URL is normalized to `https://www.upwork.com/jobs/~<id>` with query strings and fragments removed.
- The first non-empty non-URL plain-text line becomes the title.
- HTML entities and repeated whitespace are normalized in text fields.
- `$0.00 - $0.00` is stored as unknown hourly bounds.
- `Posted on: <month> <day>` is resolved to the nearest non-future calendar date using the message date year.
- Skills are deduplicated case-insensitively while preserving source order.
- `+N more` becomes `hiddenSkillCount`.
- Rounded spend suffixes such as `K`, `M`, and `B` are expanded into decimal strings and marked approximate.

Implementation note:

- The parser uses the library's default `MailMimeParser` configuration and calls `parse($rawEmail, false)`. Phase 1 does not introduce a separate application-level MIME limit configuration surface beyond the raw 1 MiB pre-parse guard.

## Normalized Data Contract

`App\Domain\Opportunities\Data\ParsedOpportunity` is a readonly DTO with these fields:

- `provider`
- `sourceMessageId`
- `externalJobId`
- `canonicalUrl`
- `title`
- `contractType`
- `hourlyMin`
- `hourlyMax`
- `currency`
- `estimatedDuration`
- `postedOn`
- `excerpt`
- `skills`
- `hiddenSkillCount`
- `paymentVerified`
- `clientRating`
- `clientSpendUsd`
- `clientSpendApproximate`
- `clientCountry`
- `templateFingerprint`

The persisted opportunity schema matches the original Phase 1 field plan:

- opportunity uniqueness is `(workspace_id, provider, external_id)`
- skill uniqueness is `(opportunity_id, name)`
- email import uniqueness is `(workspace_id, message_id)` and `(workspace_id, content_sha256)`

## Import Behavior

`App\Application\Opportunities\ImportOpportunityEmail::execute(string $workspaceId, string $rawEmail): ImportResult`

Implemented behavior:

- Calculates `sha256` of the raw email before parsing.
- Extracts a safe `Message-ID` fallback directly from the raw message for duplicate detection and quarantine records.
- Returns `duplicate` when an existing `email_imports` row matches the workspace by message ID or content hash.
- Parses outside the database transaction.
- Creates or updates one opportunity inside a transaction.
- Replaces visible skills atomically by deleting and recreating the ordered skill rows.
- Creates one `email_imports` row for imported, updated, or quarantined attempts.
- On typed parse failure, stores only safe metadata: workspace, optional safe message ID, content hash, status, error code, and timestamps.
- Never persists raw bodies, recipient addresses, or tracking values.

## Result and Error Codes

`App\Application\Opportunities\Data\ImportResult` contains:

- `status`
- `opportunityId`
- `externalJobId`
- `errorCode`

`EmailImportStatus` values used in Phase 1:

- `imported`
- `updated`
- `duplicate`
- `quarantined`

Stable parser error codes defined in `EmailParseErrorCode`:

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

As built, the parser actively emits every code above except `unsupported_template`. That enum case remains reserved for future unsupported-template branching but is not currently produced by `UpworkJobAlertParser`.

## Command Behavior

`php artisan opportunity:import-email {path} {--workspace=}`

Implemented behavior:

- Requires `--workspace` with an existing workspace ULID.
- Requires `path` to be a readable local file.
- Prints only safe fields: `status`, optional `opportunity_id`, optional `external_job_id`, and optional `error_code`.
- Returns exit code `1` for quarantined input or invalid command input.
- Returns exit code `0` for imported, updated, and duplicate results.

## Test Coverage Present in Repo

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

### Import feature tests

File: `tests/Feature/ImportOpportunityEmailTest.php`

- `it_persists_an_opportunity_and_ordered_skills_for_a_workspace`
- `it_does_not_duplicate_the_same_message_or_content_hash`
- `it_updates_the_same_job_received_under_a_new_message_id`
- `it_allows_the_same_external_job_id_in_different_workspaces`
- `it_quarantines_invalid_input_without_storing_raw_content`
- `it_rolls_back_partial_opportunity_and_skill_writes`
- `it_never_persists_tracking_parameters_or_recipient_addresses`

### Command tests

File: `tests/Feature/ImportOpportunityEmailCommandTest.php`

- `it_imports_a_local_eml_fixture_for_the_selected_workspace`
- `it_returns_a_non_zero_exit_code_for_quarantined_input`
- `it_does_not_print_email_bodies_headers_or_tracking_tokens`

### Schema tests

File: `tests/Feature/OpportunitySchemaTest.php`

- `it_enforces_workspace_scoped_opportunity_uniqueness`
- `it_enforces_workspace_scoped_message_and_hash_idempotency`
- `it_cascades_workspace_deletion_without_cross_workspace_effects`

### Behavior spec

File: `.github/docs/features/normalize_job_alert.feature`

The feature file documents the four Phase 1 acceptance scenarios, but the repository does not currently install or run a Gherkin executor. The executable validation source of truth is the PHPUnit suite.

## Validation Baseline

Phase 1 validation is configured around MariaDB 11.4 only.

Committed validation surfaces:

- `php artisan test --compact`
- `vendor/bin/phpstan analyse`
- `vendor/bin/pint --dirty --format agent`
- `composer validate --strict`
- `composer audit --locked`
- `.github/workflows/ci.yml`

Committed testing safeguards:

- `phpunit.xml` no longer configures SQLite for tests
- `.env.testing.example` provides a safe MariaDB test template
- `tests/TestCase.php` refuses destructive test execution unless the app is in `testing`, the driver is MySQL or MariaDB, and the database name ends in `_test`
- `tests/Feature/MariaDbTestingEnvironmentTest.php` asserts that the active test database is a `_test` database and that `select version()` reports MariaDB

Verified execution evidence:

- A pre-hardening PHPUnit run completed with 27 passing tests and 198 assertions before the MariaDB-only guard was introduced.
- The repository now includes `compose.yaml` with a dedicated `mariadb_test` service for repeatable local MariaDB 11.4 validation.
- Local MariaDB-only environment validation passed against the repository-managed MariaDB 11.4 container with 1 test and 4 assertions.
- Local MariaDB-only full suite validation passed against the repository-managed MariaDB 11.4 container with 28 tests and 202 assertions.
- `composer validate --strict`, `vendor/bin/phpstan analyse`, and `composer audit --locked` passed locally after the MariaDB-only hardening changes.
- GitHub Actions verification passed in the `Tests / MariaDB 11.4` job on PHP 8.4.25 with PCOV 1.0.12 against MariaDB 11.4.
- The hosted PHPUnit coverage run completed with 28 tests, 202 assertions, and 1 PHPUnit notice.
- Hosted coverage enforcement passed with 88.37% overall coverage and 91.96% Phase 1 parser/domain coverage.
- The hosted checks `Quality`, `Tests / MariaDB 11.4`, and `Secret scan` are now the exact branch-protection checks to require on `main`.

## Remaining Phase 1 Gaps After This Audit

No remaining application-scope gaps were found inside the agreed Phase 1 scope.

Remaining close-out blockers are operational:

- the canonical `PROJECT_ROADMAP.md` file requested by the review is not present in this repository, so no roadmap file was added or rewritten
