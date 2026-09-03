# Project Sheet — Freelance Opportunity Triage Platform

## Phase 1: Fixture-Driven Email Normalization

**Document role:** As-built Phase 1 specification  
**Current status:** Phase 1 application slice implemented; MariaDB-only validation and CI added in-repo  
**Last updated:** 2026-08-30

### Phase 1 Outcome

This repository implements an offline-only import slice for sanitized Upwork job-alert `.eml` fixtures. A local raw email file is parsed from its plain-text MIME part, normalized into a workspace-owned opportunity, and persisted with workspace-scoped idempotency and quarantine metadata.

Phase 1 does not contact Gmail, IMAP, Upwork, or any other external service.

### Included

- Laravel 13 application code on PHP 8.4.
- Local `.eml` fixture parsing with `zbateson/mail-mime-parser` 4.0.3.
- Upwork hourly-alert normalization from the observed plain-text template.
- Workspace-scoped opportunity persistence, skill replacement, import idempotency, and quarantine records.
- Local Artisan command `opportunity:import-email {path} {--workspace=}`.
- PHPUnit coverage for parser, import action, command behavior, and schema constraints.
- Gherkin documentation in `.github/docs/features/normalize_job_alert.feature`.

### Explicitly Excluded

- Gmail, IMAP, OAuth, mailbox polling, queues, schedules, or background ingestion.
- Fixed-price parsing, HTTP enrichment, dashboards, scoring, or Phase 2+ workflows.
- Persistence of raw email bodies, recipient addresses, tracking tokens, or full headers.

### Implemented Artifacts

#### Core application classes

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

#### Persistence

- `workspaces`
- `opportunities`
- `opportunity_skills`
- `email_imports`

Migrations live in `database/migrations` with timestamps `2026_08_27_145314` through `2026_08_27_145321`.

#### Fixtures

Sanitized fixtures live under `tests/Fixtures/Emails/upwork`:

- `hourly-client-success.eml`
- `hourly-current-template.eml`
- `hourly-operations-coordinator.eml`
- `hourly-unknown-rate.eml`

These fixtures intentionally preserve MIME structure and may include synthetic recipient addresses, synthetic message IDs, and synthetic tracking-like query values so tests can prove they are never persisted or echoed back from the import workflow.

### Supported Input Contract

The current parser supports the legacy hourly template and the current direct-link hourly template observed from `donotreply@upwork.com`.

Required characteristics:

- `From` address must exactly match `donotreply@upwork.com` or `upwork@t.upwork.com`.
- Subject must begin with `New job alert:`.
- A non-empty `text/plain` MIME part must be present.
- The plain-text body must contain at least one HTTPS Upwork job URL on `www.upwork.com` whose path matches `/jobs/~<digits>`.
- Hourly terms must match `Hourly: $<min> - $<max>`.
- Current direct-link alerts may use compact integer or decimal rates and inline terms, such as `Hourly: $<min>-$<max> · Est. time: <duration>`.
- Redirect-only alerts without an offline `/jobs/~<digits>` identifier are quarantined as `missing_job_id`; the parser never follows tracking links.

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

### Normalized Data Contract

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

### Import Behavior

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

### Result and Error Codes

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

### Command Behavior

`php artisan opportunity:import-email {path} {--workspace=}`

Implemented behavior:

- Requires `--workspace` with an existing workspace ULID.
- Requires `path` to be a readable local file.
- Prints only safe fields: `status`, optional `opportunity_id`, optional `external_job_id`, and optional `error_code`.
- Returns exit code `1` for quarantined input or invalid command input.
- Returns exit code `0` for imported, updated, and duplicate results.

### Test Coverage Present in Repo

#### Parser unit tests

File: `tests/Unit/Infrastructure/Email/UpworkJobAlertParserTest.php`

- `it_parses_each_supported_hourly_fixture`
- `it_parses_the_current_direct_link_hourly_template_without_tracking_values`
- `it_classifies_the_current_fixed_label_as_an_unsupported_contract_type`
- `it_rejects_a_redirect_only_alert_without_resolving_tracking_links`
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

#### Import feature tests

File: `tests/Feature/ImportOpportunityEmailTest.php`

- `it_persists_an_opportunity_and_ordered_skills_for_a_workspace`
- `it_does_not_duplicate_the_same_message_or_content_hash`
- `it_updates_the_same_job_received_under_a_new_message_id`
- `it_allows_the_same_external_job_id_in_different_workspaces`
- `it_quarantines_invalid_input_without_storing_raw_content`
- `it_reprocesses_an_existing_quarantine_after_parser_compatibility_improves`
- `it_rolls_back_partial_opportunity_and_skill_writes`
- `it_never_persists_tracking_parameters_or_recipient_addresses`

#### Command tests

File: `tests/Feature/ImportOpportunityEmailCommandTest.php`

- `it_imports_a_local_eml_fixture_for_the_selected_workspace`
- `it_returns_a_non_zero_exit_code_for_quarantined_input`
- `it_does_not_print_email_bodies_headers_or_tracking_tokens`

#### Schema tests

File: `tests/Feature/OpportunitySchemaTest.php`

- `it_enforces_workspace_scoped_opportunity_uniqueness`
- `it_enforces_workspace_scoped_message_and_hash_idempotency`
- `it_cascades_workspace_deletion_without_cross_workspace_effects`

#### Behavior spec

File: `.github/docs/features/normalize_job_alert.feature`

The feature file documents the four Phase 1 acceptance scenarios, but the repository does not currently install or run a Gherkin executor. The executable validation source of truth is the PHPUnit suite.

### Validation Baseline

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
- Final hosted verification run: `https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/33266436693`
- A PHPUnit-notice cleanup replaced mock-as-stub usage in `UpworkJobAlertParserTest` with proper stubs and enabled `failOnPhpunitNotice="true"` plus `displayDetailsOnPhpunitNotices="true"` in `phpunit.xml`.
- The clean hosted PHPUnit run completed with 28 tests and 202 assertions with zero PHPUnit notices.
- Hosted coverage enforcement passed with 88.37% overall coverage and 91.96% Phase 1 parser/domain coverage.
- The hosted checks `Quality`, `Tests / MariaDB 11.4`, and `Secret scan` are now the exact branch-protection checks to require on `main`.
- GitHub branch protection is enabled on `main` with pull requests required before merging, strict up-to-date status checks, required checks `Quality`, `Tests / MariaDB 11.4`, and `Secret scan`, force pushes disabled, and branch deletion disabled.

### Remaining Phase 1 Gaps After This Audit

No remaining application-scope gaps were found inside the agreed Phase 1 scope.

## Phase 2: Secure Scheduled Mailbox Intake

**Document role:** Audited implementation specification for the current phase only
**Current status:** Local implementation and protected CI complete; production activation blocked because the target redirect service returns HTTP 403 without `Location` to `HEAD`
**Last updated:** 2026-09-03
**Behavior specification:** `.github/docs/features/import_job_alerts_from_mailbox.feature`

### Action

Import newly received job-alert emails from the user's authorized, dedicated IMAP mailbox on a Laravel schedule, reuse the Phase 1 normalizer, and make delivery failures visible without duplicating opportunities or exposing mailbox data.

### Phase Outcome

Every candidate alert discovered by a scheduled poll is durably recorded before processing and is passed to `ImportOpportunityEmail` at least once. A temporary failure is retried within a bounded policy; a malformed alert is quarantined; a permanent technical failure is visible through a safe health command. Repeated delivery never creates a second opportunity.

Phase 2 adds transport and operations around the completed Phase 1 import boundary. The normalized opportunity contract remains unchanged. The compatibility spike expanded the parser's exact sender allowlist after a current alert was observed from `upwork@t.upwork.com`.

### Included

- TLS-protected IMAP access to one dedicated mailbox folder using environment-managed credentials.
- A provider-agnostic `MailboxClient` contract and one PHP IMAP adapter.
- UID/UIDVALIDITY-based discovery with a durable MariaDB checkpoint and message ledger.
- At-least-once delivery into `ImportOpportunityEmail` with workspace-scoped idempotency.
- Bounded retry state for temporary per-message failures.
- Safe quarantine/permanent-failure state for non-retryable inputs.
- A scheduled Artisan poll command, a connectivity-check command, and a health command.
- Disabled-by-default redirect-only canonicalization through the reviewed resolver boundary; production bindings and activation remain gated by ADR-004.
- MariaDB-backed tests, static analysis, dependency/security checks, and a 24-hour staging soak.

### Explicitly Excluded

- Upwork HTTP requests except the separately approved bounded redirect-only `HEAD` flow; API calls, scraping, browser automation, Cloudflare bypassing, or proposal automation.
- Gmail OAuth consent flows, IMAP IDLE, queues, daemons, WebSockets, or a permanent Node.js worker.
- Reading a personal mailbox outside the configured dedicated folder.
- Deleting, moving, flagging, or marking source messages as read.
- Scoring, APPLY/MAYBE/SKIP decisions, notifications, dashboards, or manual-description enrichment.
- Fixed-price alert parsing or changes to the normalized Phase 1 opportunity contract.
- Mailbox administration UI, multiple mailbox accounts per workspace, registration, billing, or SaaS tenant management.
- Persistence of raw email bodies, full headers, recipient addresses, credentials, access tokens, or exception traces.

### Existing Baseline to Reuse

- Laravel 13 on PHP 8.4.
- MariaDB 11.4 in local and hosted CI validation.
- `App\Application\Opportunities\ImportOpportunityEmail::execute(string $workspaceId, string $rawEmail): ImportResult`.
- `EmailImportStatus`: `imported`, `updated`, `duplicate`, and `quarantined`.
- Phase 1 workspace-scoped uniqueness by message ID, content SHA-256, and provider/external job ID.
- The existing 1 MiB raw-message limit and safe parser error codes.
- Required GitHub checks: `Quality`, `Tests / MariaDB 11.4`, and `Secret scan`.

Phase 2 must call the existing import action. It must not duplicate parser logic or write opportunities directly.

### Assumptions and Entry Gates

1. The user controls a dedicated mailbox or folder containing job alerts. The application must not scan unrelated personal mail.
2. The hosting provider permits outbound IMAP over a certificate-validated TLS connection and can run `php artisan schedule:run` at least every five minutes.
3. The mailbox supports stable IMAP UIDs and UIDVALIDITY.
4. A password, app password, or already-issued token usable by the selected IMAP adapter can be supplied through environment variables. Building an OAuth authorization flow is out of scope.
5. The application's cache store supports atomic locks on the single hosting node.

The first implementation slice is a compatibility spike. If TLS, authentication, raw RFC822 retrieval, UID behavior, or non-mutating PEEK retrieval cannot be proven on the target host, stop and amend ADR-004 before continuing. Do not silently add a Node worker, disable certificate validation, or mutate mailbox flags.

### Architecture Decision for This Phase

- Use a Laravel scheduled command rather than a permanent worker.
- Use `webklex/php-imap:^6.2` as the proposed core PHP adapter dependency; do not install the Laravel wrapper unless ADR-004 is amended with evidence.
- Hide the package behind `App\Domain\Mailbox\Contracts\MailboxClient` so infrastructure can be replaced without changing the application workflow.
- Configure UID-based sequencing and PEEK-style body retrieval. Debug protocol logging must remain disabled.
- Store only delivery state and safe error codes in MariaDB. Hold raw RFC822 bytes in memory only long enough to call the Phase 1 importer.
- Use the existing Phase 1 idempotency as the final duplicate guard; the mailbox ledger is an operational delivery guard, not a replacement.

Record this decision in `.github/docs/adr/ADR-004-scheduled-imap-polling.md` during implementation.

### Configuration Contract

Create `config/opportunity_mailbox.php` and document these keys in `.env.example` with blank or non-secret values:

| Environment key                                |               Default | Rule                                                                               |
| ---------------------------------------------- | --------------------: | ---------------------------------------------------------------------------------- |
| `OPPORTUNITY_MAILBOX_ENABLED`                  |               `false` | The scheduler performs no network work unless explicitly enabled.                  |
| `OPPORTUNITY_MAILBOX_WORKSPACE_ID`             |                 blank | Must resolve to an existing workspace ULID when enabled.                           |
| `OPPORTUNITY_MAILBOX_KEY`                      |             `primary` | Non-secret identifier, maximum 64 characters.                                      |
| `OPPORTUNITY_MAILBOX_HOST`                     |                 blank | Required when enabled; never printed by routine commands.                          |
| `OPPORTUNITY_MAILBOX_PORT`                     |                 `993` | Validated integer from 1 through 65535.                                            |
| `OPPORTUNITY_MAILBOX_ENCRYPTION`               |                 `ssl` | Only implicit TLS (`ssl`) or STARTTLS (`tls`) is accepted in production.           |
| `OPPORTUNITY_MAILBOX_VALIDATE_CERT`            |                `true` | Must be `true` outside tests.                                                      |
| `OPPORTUNITY_MAILBOX_USERNAME`                 |                 blank | Secret-adjacent; never logged or printed.                                          |
| `OPPORTUNITY_MAILBOX_PASSWORD`                 |                 blank | Secret; never committed, persisted, logged, printed, or included in fixtures.      |
| `OPPORTUNITY_MAILBOX_FOLDER`                   |                 blank | Required when enabled; must select a dedicated folder only.                        |
| `OPPORTUNITY_MAILBOX_CANDIDATE_FROM`           | `upwork@t.upwork.com` | Comma-separated exact envelope sender allowlist; Phase 1 remains authoritative.    |
| `OPPORTUNITY_MAILBOX_CANDIDATE_SUBJECT_PREFIX` |      `New job alert:` | Envelope pre-filter only.                                                          |
| `OPPORTUNITY_MAILBOX_BATCH_SIZE`               |                  `25` | Clamp to 1–100.                                                                    |
| `OPPORTUNITY_MAILBOX_INITIAL_LOOKBACK_HOURS`   |                  `24` | Clamp to 1–168; used only without a valid checkpoint or after UIDVALIDITY changes. |
| `OPPORTUNITY_MAILBOX_MAX_ATTEMPTS`             |                   `3` | Clamp to 1–5.                                                                      |
| `OPPORTUNITY_MAILBOX_HEALTH_MAX_AGE_MINUTES`   |                  `15` | A completed run older than this is stale.                                          |

Configuration validation must happen before connecting. Invalid or insecure production configuration returns a stable error code and performs no network request.

### Application Contracts

#### Mailbox boundary

Create `App\Domain\Mailbox\Contracts\MailboxClient` with methods equivalent to:

```php
public function probe(): MailboxProbeResult;

public function discover(MailboxCursor $cursor, int $limit): DiscoveredMailboxBatch;

public function fetchRaw(MailboxMessageReference $message, int $maximumBytes): string;

public function close(): void;
```

Required readonly DTOs under `app/Domain/Mailbox/Data`:

- `MailboxCursor`: previous UIDVALIDITY, last discovered UID, and optional initial-lookback timestamp.
- `MailboxMessageReference`: UID, reported RFC822 size, and no body or recipient data.
- `DiscoveredMailboxBatch`: current UIDVALIDITY, ascending candidate references, and highest discovered candidate UID.
- `MailboxProbeResult`: safe success metadata only; no hostname, username, folder contents, or server greeting.

Create `App\Application\Mailbox\Data\MailboxRunResult` for the run status, safe counters, and optional stable error code.

`discover` may inspect envelope headers on the server to match the configured sender and subject prefix, but must not persist those headers. `fetchRaw` must return the complete original RFC822 headers and body, not a reconstructed message or only the decoded body.

#### Stable enums

Create:

- `MailboxMessageStatus`: `pending`, `retry_wait`, `imported`, `updated`, `duplicate`, `quarantined`, `permanently_failed`.
- `MailboxRunStatus`: `running`, `succeeded`, `partial`, `failed`, `skipped_overlap`.
- `MailboxIntakeErrorCode` using namespaced values:
    - `mailbox.configuration_invalid`
    - `mailbox.insecure_transport`
    - `mailbox.authentication_failed`
    - `mailbox.connection_failed`
    - `mailbox.folder_unavailable`
    - `mailbox.uidvalidity_changed`
    - `mailbox.message_too_large`
    - `mailbox.message_fetch_failed`
    - `mailbox.import_failed`
    - `mailbox.retry_exhausted`

Phase 1 quarantine codes are stored unchanged with an `email.` prefix, for example `email.missing_plain_text`. Console output, logs, database rows, and health responses may contain these stable codes but not raw exception messages.

### Persistence Contract

Add three ULID-backed, workspace-owned tables.

#### `mailbox_checkpoints`

- `id`
- `workspace_id` with `cascadeOnDelete()`
- `mailbox_key` string(64)
- `uid_validity` unsigned big integer, nullable until first successful discovery
- `last_discovered_uid` unsigned big integer, default `0`
- timestamps
- unique: `(workspace_id, mailbox_key)`

#### `mailbox_messages`

- `id`
- `workspace_id` with `cascadeOnDelete()`
- `opportunity_id`, nullable, with `nullOnDelete()`
- `mailbox_key` string(64)
- `uid_validity` unsigned big integer
- `message_uid` unsigned big integer
- `status` string(32)
- `attempt_count` unsigned tiny integer, default `0`
- `next_attempt_at`, nullable timestamp
- `error_code` nullable string(96)
- `first_seen_at` timestamp
- `processed_at`, nullable timestamp
- timestamps
- unique: `(workspace_id, mailbox_key, uid_validity, message_uid)`
- retry index: `(workspace_id, mailbox_key, status, next_attempt_at)`

#### `mailbox_runs`

- `id`
- `workspace_id` with `cascadeOnDelete()`
- `mailbox_key` string(64)
- `status` string(32)
- `started_at` timestamp
- `finished_at`, nullable timestamp
- unsigned integer counters: `discovered_count`, `processed_count`, `imported_count`, `updated_count`, `duplicate_count`, `quarantined_count`, `retry_scheduled_count`, `permanent_failure_count`
- `error_code` nullable string(96)
- timestamps
- health index: `(workspace_id, mailbox_key, started_at)`

Create Eloquent models and workspace relations. Do not add raw email, sender, recipient, subject, username, hostname, exception-message, or credential columns.

### Polling and Delivery Algorithm

Implement `App\Application\Mailbox\PollOpportunityMailbox::execute(string $workspaceId): MailboxRunResult` in this order:

1. Validate enabled configuration and workspace ownership before any connection.
2. Acquire an atomic lock named from workspace ID and non-secret mailbox key with a 10-minute expiry. A second poll exits safely as `skipped_overlap` without connecting.
3. Create a `running` mailbox-run record.
4. Load or create the workspace/mailbox checkpoint.
5. Connect, select the configured folder, and obtain current UIDVALIDITY.
6. Discover candidate UIDs in ascending order after the checkpoint. On first use or UIDVALIDITY change, search only the configured lookback window. A UIDVALIDITY change is recorded as a safe warning and relies on Phase 1 idempotency during the bounded rescan.
7. In one database transaction, insert ledger rows with `pending` status using `insert-or-ignore`, then advance the checkpoint only to the highest UID represented by a committed ledger row. Never advance a checkpoint for an unrecorded candidate.
8. Select due `pending` or `retry_wait` rows in ascending UID order and process sequentially. Do not hold all raw messages in memory.
9. Reject a server-reported message larger than 1,048,576 bytes without fetching its body; mark it `quarantined` with `mailbox.message_too_large`.
10. Fetch complete raw RFC822 bytes using UID sequencing and PEEK semantics. Confirm the returned byte length is within the same limit.
11. Call `ImportOpportunityEmail::execute($workspaceId, $rawEmail)` exactly once for that processing attempt and immediately release the raw string after the call.
12. Map `imported`, `updated`, `duplicate`, and `quarantined` to the ledger. If a retry receives `duplicate` with no opportunity ID because a prior attempt already committed a quarantine before the ledger update failed, resolve the existing `email_imports` row by workspace and content hash and preserve its quarantine code. Persist only the returned opportunity ID and safe error code.
13. For a retryable per-message transport or unexpected import failure, increment `attempt_count` and set `retry_wait` with delays of 5 minutes after attempt 1 and 15 minutes after attempt 2. After attempt 3, set `permanently_failed` with `mailbox.retry_exhausted`.
14. Continue the batch after a quarantined or retryable message. A connection-level failure ends the run without advancing uncommitted discovery state.
15. Close the IMAP connection in `finally`, finalize safe counters/status, and release the lock.

A run is:

- `succeeded` when discovery completed and no message was quarantined, deferred, or permanently failed;
- `partial` when discovery completed but UIDVALIDITY changed or at least one message was quarantined, deferred, or permanently failed;
- `failed` when configuration, connection, authentication, TLS, folder selection, or the run-level transaction prevents safe discovery.

No command may delete, move, mark read/unread, or otherwise modify a source message.

### Commands and Schedule

#### `opportunity:mailbox-check`

- Validates configuration and probes TLS authentication/folder selection without fetching message bodies.
- Returns exit code `0` on success and `1` on failure.
- Prints only `status` and optional stable `error_code`.

#### `opportunity:poll-mailbox {--workspace=}`

- Uses the configured workspace by default; `--workspace` is permitted for controlled local/staging use and must resolve to an existing workspace.
- Returns `0` for `succeeded` or `skipped_overlap`, `2` for `partial`, and `1` for `failed` or invalid input.
- Prints only run status, safe counters, and optional stable error code.

#### `opportunity:mailbox-health {--workspace=} {--json}`

- Reports `healthy`, `degraded`, `unhealthy`, or `never_run` from persisted state without connecting to IMAP.
- `healthy`: latest completed run is within the configured age and no retry is overdue or permanently failed.
- `degraded`: the latest run is recent but partial, quarantined work exists, or a retry is pending but not overdue.
- `unhealthy`: configuration is invalid, the latest run failed or is stale, a retry is overdue, or any message is permanently failed.
- Returns `0` only for `healthy`; all other states return `1`.
- Human and JSON output contain timestamps, counters, and stable codes only.

In `routes/console.php`, schedule the poll command with:

- `everyFiveMinutes()`
- `withoutOverlapping(10)`
- execution only when `OPPORTUNITY_MAILBOX_ENABLED=true`

The deployment runbook must document a provider cron entry that invokes `php artisan schedule:run` every minute. Do not use `runInBackground()` or require a queue worker in this phase.

### Ordered Implementation Tasks

1. **Compatibility spike and ADR**
    - Add `webklex/php-imap:^6.2` and commit its lockfile change.
    - Draft `.github/docs/adr/ADR-004-scheduled-imap-polling.md`.
    - Prove PHP 8.4 connectivity, certificate validation, folder selection, UID/UIDVALIDITY, raw RFC822 retrieval, size lookup, and unchanged message flags on the target host.
    - Stop and amend the ADR if any proof fails.
2. **Configuration and domain contract**
    - Add `config/opportunity_mailbox.php` and safe `.env.example` placeholders.
    - Add the mailbox contract, DTOs, enums, and typed exceptions under `app/Domain/Mailbox`.
    - Register `MailboxConfiguration` in `App\Providers\AppServiceProvider`; bind `MailboxClient` when the production adapter is implemented in task 4.
3. **MariaDB delivery state**
    - Add migrations, models, casts, fillable fields, factories where useful, and workspace relations for checkpoints, messages, and runs.
    - Add all uniqueness, retry, foreign-key, and cascade constraints from this sheet.
4. **IMAP adapter**
    - Implement `App\Infrastructure\Email\WebklexImapMailboxClient` using TLS validation, UID sequencing, envelope filtering, reported-size checks, raw RFC822 retrieval, and PEEK semantics.
    - Keep package debug logging disabled and translate package exceptions to stable typed exceptions.
5. **Application workflow**
    - Implement `App\Application\Mailbox\PollOpportunityMailbox` and `MailboxRunResult`.
    - Implement atomic discovery/checkpoint persistence, sequential processing, status mapping, retry timing, run summaries, and lock behavior.
    - Reuse `ImportOpportunityEmail`; do not alter opportunity parsing or persistence.
6. **Operational commands and scheduler**
    - Add `CheckOpportunityMailboxCommand`, `PollOpportunityMailboxCommand`, and `OpportunityMailboxHealthCommand`.
    - Add the guarded five-minute schedule in `routes/console.php`.
    - Add `.github/docs/runbooks/mailbox-intake.md` with safe setup, cron, health, disable, and rollback instructions.
7. **TDD/BDD completion**
    - Write the named tests below before their production code, using `Tests\Support\Fakes\FakeMailboxClient` for deterministic no-network behavior.
    - Map every Gherkin scenario to at least one PHPUnit feature test.
    - Run the complete MariaDB suite, PHPStan, Pint, Composer validation/audit, and secret scan.
    - Perform and record the 24-hour staging soak before marking Phase 2 complete.
8. **As-built documentation**
    - Update this sheet to match the audited implementation exactly.
    - Update `PROJECT_ROADMAP.md` to mark Phase 2 complete only after its exit criteria pass.
    - Keep Phase 3 implementation detail out of the Copilot context.

### Test Plan

#### Domain/configuration unit tests

File: `tests/Unit/Domain/Mailbox/MailboxConfigurationTest.php`

- `it_rejects_missing_required_configuration_when_mailbox_intake_is_enabled`
- `it_rejects_insecure_transport_or_disabled_certificate_validation_outside_tests`
- `it_clamps_batch_retry_and_lookback_limits`
- `it_parses_an_exact_candidate_sender_allowlist`
- `it_performs_no_probe_when_mailbox_intake_is_disabled`

#### IMAP adapter unit/contract tests

File: `tests/Unit/Infrastructure/Email/WebklexImapMailboxClientTest.php`

- `it_uses_uid_sequence_peek_fetching_and_certificate_validation`
- `it_discovers_only_matching_candidate_envelopes_in_ascending_uid_order`
- `it_discovers_candidate_envelopes_from_each_allowlisted_sender`
- `it_uses_a_bounded_lookback_after_uidvalidity_changes`
- `it_returns_complete_raw_rfc822_bytes`
- `it_rejects_an_oversized_message_before_fetching_its_body`
- `it_translates_authentication_connection_and_folder_errors_to_stable_codes`
- `it_never_enables_protocol_debug_logging_or_writes_message_flags`

#### Polling workflow feature tests — implemented

File: `tests/Feature/PollOpportunityMailboxTest.php`

- `it_imports_a_new_candidate_alert_and_advances_its_checkpoint`
- `it_records_discovery_before_processing_and_never_advances_past_an_unrecorded_uid`
- `it_skips_a_remote_uid_already_finalized_in_the_same_uidvalidity_namespace`
- `it_rescans_a_bounded_window_after_uidvalidity_changes_without_duplicate_opportunities`
- `it_does_not_fetch_a_retry_from_an_invalidated_uidvalidity_namespace`
- `it_retries_a_temporary_fetch_failure_and_imports_exactly_once`
- `it_reconciles_a_committed_quarantine_after_a_ledger_update_failure`
- `it_marks_a_message_permanently_failed_after_the_third_temporary_failure`
- `it_quarantines_an_unsupported_candidate_and_continues_the_batch`
- `it_does_not_advance_the_checkpoint_after_a_connection_level_failure`
- `it_prevents_overlapping_polls_for_the_same_workspace_and_mailbox`
- `it_never_persists_raw_email_headers_bodies_recipients_or_credentials`
- `it_never_logs_raw_exceptions_or_secrets`

These 13 MariaDB-backed tests use `Tests\Support\Fakes\FakeMailboxClient` and perform no external network access.

#### Command tests — implemented

File: `tests/Feature/OpportunityMailboxCommandTest.php`

- `it_reports_a_safe_successful_connectivity_check`
- `it_reports_a_safe_connectivity_failure_without_credentials_or_server_details`
- `it_prints_only_safe_poll_counters_and_uses_documented_exit_codes`
- `it_reports_healthy_degraded_unhealthy_and_never_run_states_from_persisted_data`
- `it_emits_safe_machine_readable_health_json`

All five command behaviors are implemented with MariaDB-backed tests.

#### Schedule tests — implemented

File: `tests/Feature/OpportunityMailboxScheduleTest.php`

- `it_schedules_the_poll_every_five_minutes_without_overlap_when_enabled`
- `it_does_not_schedule_network_work_when_disabled`

#### Schema and isolation tests

File: `tests/Feature/MailboxSchemaTest.php`

- `it_enforces_workspace_mailbox_uid_uniqueness`
- `it_enforces_workspace_mailbox_checkpoint_uniqueness`
- `it_allows_the_same_uid_namespace_in_different_workspaces`
- `it_cascades_workspace_deletion_without_cross_workspace_effects`
- `it_nulls_the_opportunity_reference_without_deleting_delivery_history`
- `it_stores_only_safe_delivery_metadata`

#### Behavior traceability

| Gherkin scenario                                                    | Primary PHPUnit case                                                           |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Import a newly received alert on the scheduled poll                 | `it_imports_a_new_candidate_alert_and_advances_its_checkpoint`                 |
| Ignore a candidate already completed by an earlier poll             | `it_skips_a_remote_uid_already_finalized_in_the_same_uidvalidity_namespace`    |
| Retry a temporary fetch failure without duplicating the opportunity | `it_retries_a_temporary_fetch_failure_and_imports_exactly_once`                |
| Quarantine an unsupported candidate and continue the batch          | `it_quarantines_an_unsupported_candidate_and_continues_the_batch`              |
| Report mailbox setup failures without leaking secrets               | `it_reports_a_safe_connectivity_failure_without_credentials_or_server_details` |
| Make an exhausted delivery failure actionable                       | `it_marks_a_message_permanently_failed_after_the_third_temporary_failure`      |

The repository does not currently execute Gherkin directly. Phase 2 keeps the `.feature` file as the behavior contract and makes the mapped PHPUnit feature tests the executable source of truth; adding Behat is outside this phase.

### Performance and Reliability Budgets

- Process at most 25 messages per default poll and never more than 100.
- Fetch and import sequentially so only one raw message is retained in memory.
- Enforce the existing 1 MiB maximum before MIME parsing and, where the server reports size, before body retrieval.
- Use bounded connection/read timeouts; no single poll should occupy the overlap lock for more than 10 minutes.
- Commit discovered ledger rows before advancing the checkpoint.
- Never retry a Phase 1 typed quarantine result.
- A normal empty or single-message poll should complete within 60 seconds on staging.
- At-least-once processing plus Phase 1 idempotency must produce exactly one opportunity for repeated delivery.

### Security and Privacy Requirements

- TLS certificate validation is mandatory outside tests; there is no insecure fallback.
- Credentials exist only in deployment environment configuration and are never stored in MariaDB.
- `APP_DEBUG=false` is required in staging/production.
- IMAP protocol debug logging is disabled.
- Raw messages, full headers, mailbox addresses, subjects, recipient data, tracking values, credentials, and exception messages are absent from database rows, logs, console output, fixtures, screenshots, CI artifacts, and PR descriptions.
- Error handling uses allowlisted stable codes and safe counters only.
- Candidate envelope filtering reduces unnecessary body access; Phase 1 independently validates sender, subject, MIME structure, and canonical job URL.
- The application does not modify mailbox flags or message placement.
- Automated tests use a fake mailbox boundary and perform no external network requests.
- `composer audit --locked` and secret scanning remain required merge checks after adding the dependency.

### Accessibility

No graphical UI is added. CLI status must not rely on color alone, must use stable words/codes, and must support `--json` for assistive or automated consumers.

### Manual Verification and 24-Hour Soak

Before Phase 2 is complete:

First resolve the ADR-004 redirect transport blocker through a separately reviewed decision and passing target-host proof. Keep mailbox intake, redirect resolution, controlled polling, and the soak disabled until then.

1. Run `opportunity:mailbox-check` on the target host and record only pass/fail plus the stable code.
2. Confirm a fetched source message remains present and its flags are unchanged.
3. Install the provider cron and verify scheduled timestamps through persisted `mailbox_runs`, not by exposing mail data in logs.
4. Let staging poll for 24 hours with real authorized alerts.
5. Reconcile candidate UIDs against ledger rows and confirm no candidate message loss, no duplicate opportunity, no overdue retry, and no secret/raw-content leakage.
6. Run `opportunity:mailbox-health --json` and confirm `healthy` at the end of the soak.

The soak evidence should contain counts, timestamps, commit SHA, and CI URL only.

### Risks and Mitigations

| Risk                                                                   | Mitigation / stop condition                                                                                                                           |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hosting blocks outbound IMAP, required auth, or cron cadence           | Compatibility spike first; retain the Phase 1 local `.eml` command; stop before building around an unverified transport.                              |
| IMAP library changes message flags or cannot return exact RFC822 bytes | PEEK/flag and parser-contract proofs are mandatory; amend ADR-004 or stop.                                                                            |
| UIDVALIDITY reset causes a rescan                                      | Namespace ledger by UIDVALIDITY, bound the lookback, and rely on Phase 1 content/job idempotency.                                                     |
| Checkpoint advances before durable discovery                           | Ledger insert and checkpoint update share one transaction; explicit rollback test.                                                                    |
| Temporary failures create an infinite hot loop                         | Persist attempts and next-attempt timestamps; fixed bounded retry schedule.                                                                           |
| A template change silently drops alerts                                | Envelope filter remains broad enough for configured alerts; raw messages still pass Phase 1 validation and quarantine; health degrades on quarantine. |
| Redirect-only alerts omit an offline canonical job identifier          | Target-host `HEAD` returned HTTP 403 without `Location`; keep resolution and intake disabled and amend ADR-004 before testing another transport.      |
| Secrets or personal mail appear in diagnostics                         | Dedicated folder, minimal schema, stable codes, no protocol debug, and adversarial output/log tests.                                                  |
| Large backlog exceeds hosting limits                                   | Initial lookback, batch cap, sequential fetching, and short scheduler runs.                                                                           |

### Rollback

- Set `OPPORTUNITY_MAILBOX_ENABLED=false` to stop all scheduled network work immediately.
- Keep the Phase 1 local `.eml` import command available.
- Do not delete imported opportunities or delivery ledgers during an operational rollback.
- Revert application code only after confirming migrations remain forward-compatible; destructive migration rollback is not part of the production procedure.

### Definition of Ready

- This sheet and `.github/docs/features/import_job_alerts_from_mailbox.feature` are approved.
- Phase 1 remains green on MariaDB 11.4 with all required GitHub checks.
- The compatibility spike is the first implementation task and has an explicit stop condition.
- Named tests, privacy boundaries, retry semantics, configuration keys, and rollback are defined.
- Copilot receives only this Phase 2 sheet, its feature file, ADR-004, and the relevant Phase 1 interfaces/classes—not future phase detail.

### Definition of Done

- All mapped PHPUnit tests and the complete existing suite pass on MariaDB 11.4.
- PHPStan, Pint, Composer validation/audit, coverage gates, secret scan, and protected required checks are green.
- The live compatibility proof and 24-hour staging soak meet every exit criterion.
- No raw mail, personal mailbox data, secrets, or unsafe exception text is retained or exposed.
- ADR-004 and the mailbox runbook are committed.
- `project_sheet.md` is updated from draft to the audited as-built implementation.
- `PROJECT_ROADMAP.md` marks Phase 2 complete only after deployment verification.
