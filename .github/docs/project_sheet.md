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
- `hourly-current-sanitized.eml`
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
- Returns `duplicate` when an existing successful `email_imports` row matches the workspace by message ID or content hash.
- Returns the stored `quarantined` status and safe error code without reparsing or updating when either identity matches a historical quarantine in the workspace.
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
- `it_parses_indented_hourly_terms_from_the_current_template`
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
- `it_preserves_an_existing_quarantine_without_reparsing`
- `it_keeps_quarantine_deduplication_scoped_to_the_workspace`
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
**Current status:** Complete; polling-deadline correction passed protected CI and target-host verification
**Last updated:** 2026-09-08
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
- Direct canonical `/jobs/~<digits>` alert intake; redirect-only alerts quarantine as `email.missing_job_id` without an Upwork HTTP request.
- MariaDB-backed tests, static analysis, dependency/security checks, and a 24-hour staging soak.

### Explicitly Excluded

- All Upwork HTTP requests, API calls, scraping, browser automation, Cloudflare bypassing, crawler impersonation, or proposal automation.
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
3. Start one monotonic poll budget immediately after lock acquisition: 480 seconds for work, 60 seconds reserved for finalization, 30 seconds for cleanup, and a final 30-second lock-release margin.
4. Create a `running` mailbox-run record.
5. Load or create the workspace/mailbox checkpoint.
6. Connect, select the configured folder, and obtain current UIDVALIDITY.
7. Discover candidate UIDs in ascending order after the checkpoint. On first use or UIDVALIDITY change, search only the configured lookback window. A UIDVALIDITY change is recorded as a safe warning and starts a bounded rescan in the new namespace.
8. In one database transaction, permanently fail every `pending` or `retry_wait` row in obsolete UIDVALIDITY namespaces with `mailbox.uidvalidity_changed`, clear its retry timestamp, preserve its attempt count and all terminal history, insert new-namespace ledger rows with `pending` status using `insert-or-ignore`, and advance the checkpoint only to the highest UID represented by a committed ledger row. Scope every operation to the workspace and mailbox key. Never fetch an obsolete UID against the new namespace, even when its numeric UID is reused.
9. Select due `pending` or `retry_wait` rows in ascending UID order and process sequentially. Do not hold all raw messages in memory.
10. Treat a non-positive reported size on a reconstructed pending or retry reference as unknown. Before body retrieval, request only UID and RFC822.SIZE metadata for that UID. Require the response to identify the requested UID and contain a positive integer size; otherwise fail the attempt with `mailbox.message_fetch_failed` without requesting the body.
11. Reject a server-reported message larger than 1,048,576 bytes without fetching its body; mark it `quarantined` with `mailbox.message_too_large`.
12. Fetch complete raw RFC822 bytes using UID sequencing and PEEK semantics. Confirm the returned byte length is within the same limit.
13. Call `ImportOpportunityEmail::execute($workspaceId, $rawEmail)` exactly once for that processing attempt and immediately release the raw string after the call.
14. Map `imported`, `updated`, `duplicate`, and `quarantined` to the ledger. A workspace-scoped Message-ID or content-hash match on a historical quarantine returns its stored terminal status and safe code without invoking the parser or updating the import row. There is no historical replay or automatic recovery; any future recovery requires a separately reviewed append-only design. Persist only the returned opportunity ID and safe error code.
15. For a retryable per-message transport or unexpected import failure, increment `attempt_count` and set `retry_wait` with delays of 5 minutes after attempt 1 and 15 minutes after attempt 2. After attempt 3, set `permanently_failed` with `mailbox.retry_exhausted`.
16. Continue the batch after a quarantined or retryable message. A connection-level failure ends the run without advancing uncommitted discovery state.
17. Before and after discovery, each message, and each blocking transport operation, enforce the shared work deadline. Retime the live IMAP stream to the remaining allowance before each read or write so slow-drip responses cannot extend the absolute deadline. Apply shrinking MariaDB statement and lock-wait limits for the active phase.
18. If the budget expires before discovery commits, roll back discovery and fail the run with `mailbox.poll_budget_exhausted`. If it expires after discovery commits, leave interrupted work pending without increasing its attempt count and finalize the run as `partial` with accurate committed counters and the same stable code.
19. Bound finalization and cleanup within their reserved windows. Once graceful IMAP logout cannot fit, reset the local stream without another network round trip, release the lock before its 600-second expiry, and restore database session limits.

A run is:

- `succeeded` when discovery completed and no message was quarantined, deferred, or permanently failed;
- `partial` when discovery completed but UIDVALIDITY changed, the work budget expired, or at least one message was quarantined, deferred, or permanently failed;
- `failed` when configuration, connection, authentication, TLS, folder selection, the work budget, or the run-level transaction prevents safe discovery.

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
- `it_fetches_size_metadata_before_fetching_an_unknown_size_message`
- `it_rejects_an_unknown_size_oversized_message_before_fetching_its_body`
- `it_fails_safely_without_fetching_a_body_when_size_metadata_is_invalid`
- `it_translates_authentication_connection_and_folder_errors_to_stable_codes`
- `it_never_enables_protocol_debug_logging_or_writes_message_flags`

#### Polling workflow feature tests — implemented

File: `tests/Feature/PollOpportunityMailboxTest.php`

- `it_imports_a_new_candidate_alert_and_advances_its_checkpoint`
- `it_records_discovery_before_processing_and_never_advances_past_an_unrecorded_uid`
- `it_skips_a_remote_uid_already_finalized_in_the_same_uidvalidity_namespace`
- `it_rescans_a_bounded_window_after_uidvalidity_changes_without_duplicate_opportunities`
- `it_finalizes_unfinished_obsolete_namespaces_and_processes_the_new_namespace`
- `it_retries_a_temporary_fetch_failure_and_imports_exactly_once`
- `it_quarantines_an_oversized_pending_message_discovered_earlier_and_continues_the_batch`
- `it_reports_an_unknown_size_metadata_failure_safely_without_importing`
- `it_preserves_a_committed_quarantine_after_a_ledger_update_failure`
- `it_marks_a_message_permanently_failed_after_the_third_temporary_failure`
- `it_quarantines_an_unsupported_candidate_and_continues_the_batch`
- `it_does_not_advance_the_checkpoint_after_a_connection_level_failure`
- `it_prevents_overlapping_polls_for_the_same_workspace_and_mailbox`
- `it_never_persists_raw_email_headers_bodies_recipients_or_credentials`
- `it_never_logs_raw_exceptions_or_secrets`

These 15 MariaDB-backed tests use `Tests\Support\Fakes\FakeMailboxClient` and perform no external network access. The adapter contract tests separately exercise `WebklexImapMailboxClient` with a fake IMAP protocol.

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

| Gherkin scenario                                                    | Primary PHPUnit case                                                                                                                                              |
| ------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Import a newly received alert on the scheduled poll                 | `it_imports_a_new_candidate_alert_and_advances_its_checkpoint`                                                                                                    |
| Ignore a candidate already completed by an earlier poll             | `it_skips_a_remote_uid_already_finalized_in_the_same_uidvalidity_namespace`                                                                                       |
| Retry a temporary fetch failure without duplicating the opportunity | `it_retries_a_temporary_fetch_failure_and_imports_exactly_once`                                                                                                   |
| Quarantine an oversized pending message before body retrieval       | `it_quarantines_an_oversized_pending_message_discovered_earlier_and_continues_the_batch`; `it_rejects_an_unknown_size_oversized_message_before_fetching_its_body` |
| Import a retry whose reconstructed reference has unknown size       | `it_retries_a_temporary_fetch_failure_and_imports_exactly_once`; `it_fetches_size_metadata_before_fetching_an_unknown_size_message`                               |
| Fail safely when required size metadata is missing or invalid       | `it_fails_safely_without_fetching_a_body_when_size_metadata_is_invalid`; `it_reports_an_unknown_size_metadata_failure_safely_without_importing`                   |
| Finalize unfinished work when UIDVALIDITY changes                   | `it_finalizes_unfinished_obsolete_namespaces_and_processes_the_new_namespace`; `it_reports_healthy_degraded_unhealthy_and_never_run_states_from_persisted_data`   |
| Preserve a historical quarantine on ordinary redelivery             | `it_preserves_an_existing_quarantine_without_reparsing`; `it_preserves_a_committed_quarantine_after_a_ledger_update_failure`                                      |
| Quarantine an unsupported candidate and continue the batch          | `it_quarantines_an_unsupported_candidate_and_continues_the_batch`                                                                                                 |
| Report mailbox setup failures without leaking secrets               | `it_reports_a_safe_connectivity_failure_without_credentials_or_server_details`                                                                                    |
| Make an exhausted delivery failure actionable                       | `it_marks_a_message_permanently_failed_after_the_third_temporary_failure`                                                                                         |

The repository does not currently execute Gherkin directly. Phase 2 keeps the `.feature` file as the behavior contract and makes the mapped PHPUnit feature tests the executable source of truth; adding Behat is outside this phase.

### Performance and Reliability Budgets

- Process at most 25 messages per default poll and never more than 100.
- Fetch and import sequentially so only one raw message is retained in memory.
- Enforce the existing 1 MiB maximum before MIME parsing and before body retrieval. Resolve an unknown reconstructed size through a UID-based metadata-only request, and do not retrieve the body when valid size metadata is unavailable.
- Enforce one monotonic 480-second work deadline through application, IMAP connect/read/write, and MariaDB waits; inactivity timeouts alone are insufficient.
- Reserve bounded finalization and cleanup windows and a 30-second release margin inside the unchanged 10-minute overlap locks.
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

1. Run `opportunity:mailbox-check` on the target host and record only pass/fail plus the stable code.
2. Confirm a fetched source message remains present and its flags are unchanged.
3. Install the provider cron and verify scheduled timestamps through persisted `mailbox_runs`, not by exposing mail data in logs.
4. Let staging poll for 24 hours with real authorized alerts.
5. Reconcile candidate UIDs against ledger rows and confirm no candidate message loss, no duplicate opportunity, no overdue retry, and no secret/raw-content leakage.
6. Run `opportunity:mailbox-health --json` and confirm there are no transport, retry, or delivery failures. A `degraded` result caused only by expected `email.missing_job_id` or `email.unsupported_contract_type` quarantines is acceptable because redirect-only and fixed-price alerts are explicitly outside the supported parser scope.

The soak evidence should contain counts, timestamps, commit SHA, and CI URL only.

Controlled staging verification on 2026-09-04 at commit `32ff898` imported five direct-link alerts and safely quarantined nine redirect-only alerts as `email.missing_job_id` plus one fixed-price alert as `email.unsupported_contract_type`. All 15 discovered messages were processed with no retry or permanent failure. No historical quarantine was replayed or mutated.

The first soak interval did not produce mailbox runs because the existing provider cron targeted another application location and used the host's default PHP 8.5 runtime. The cron entry was corrected to target this deployment with the verified PHP 8.4 binary, and the 24-hour clock was restarted rather than accepting the inactive interval.

The corrected soak ran from 2026-09-05 18:09:13 UTC through 2026-09-06 18:35:02 UTC on commit `4cdeb1a`. It produced 294 polls with a maximum observed gap of 301 seconds. Twelve discovered messages were all processed: seven direct-link alerts imported and five unsupported alerts quarantined under only the accepted codes (`email.missing_job_id`: two; `email.unsupported_contract_type`: three). There were no duplicates, pending messages, retries, overdue retries, or permanent failures.

Completion review found that health selected the oldest terminal quarantine across all history, allowing a pre-fix parser quarantine to keep later clean polls degraded. Commit `43f1ee5` scopes quarantine health to the latest completed run, while permanent failures and retry states remain global and actionable. The complete MariaDB suite passed 91 tests with 747 assertions; PHPStan, Pint, Composer validation/audit, coverage gates, and all protected checks passed in [CI run 34052269465](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/34052269465). The deployed final health is `healthy`.

This soak and CI record remain historical evidence for the commits named above. The UIDVALIDITY and quarantine-history recovery correction was reviewed and merged in PR #3 at commit `49a5a3b`. Protected `Quality`, `Tests / MariaDB 11.4`, and `Secret scan` checks passed in [CI run 34154391079](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/34154391079). On 2026-09-08, the exact merge commit was deployed with PHP 8.4 and a clean worktree. The safe connectivity check and one controlled poll succeeded with zero discovered messages, retries, or permanent failures. The next provider-scheduled run completed at 18:30:01 UTC with the same zero-failure counters, and persisted health remained `healthy`. No historical quarantine was replayed and no Upwork HTTP request was made.

The production adapter continued to fetch raw messages with `BODY.PEEK[]` and contains no flag, move, or delete operation. The prior target-host PEEK proof established unchanged source flags, and the corrected soak exercised that same reviewed adapter path.

The later deadline review found that static socket inactivity timeouts did not bound a slow-drip IMAP response and that database waits or graceful logout could outlive the 600-second locks. The monotonic deadline correction was reviewed in [PR #5](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/5) and merged as commit `161c97d`. Protected `Quality`, `Tests / MariaDB 11.4`, and `Secret scan` checks passed in [CI run 34269173609](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/34269173609). On 2026-09-08, the exact merge commit was deployed with PHP 8.4 and a clean worktree. Connectivity and a controlled poll succeeded with zero discovered messages, retries, or permanent failures. The next provider-scheduled run completed at 19:35:03 UTC with the same zero-failure counters, and persisted health remained `healthy`. Exactly one provider scheduler entry used PHP 8.4. No historical quarantine was replayed and no Upwork HTTP request was made. Phase 2 is complete.

### Risks and Mitigations

| Risk                                                                   | Mitigation / stop condition                                                                                                                           |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Hosting blocks outbound IMAP, required auth, or cron cadence           | Compatibility spike first; retain the Phase 1 local `.eml` command; stop before building around an unverified transport.                              |
| IMAP library changes message flags or cannot return exact RFC822 bytes | PEEK/flag and parser-contract proofs are mandatory; amend ADR-004 or stop.                                                                            |
| UIDVALIDITY reset causes a rescan                                      | Namespace ledger by UIDVALIDITY, bound the lookback, and rely on Phase 1 content/job idempotency.                                                     |
| Checkpoint advances before durable discovery                           | Ledger insert and checkpoint update share one transaction; explicit rollback test.                                                                    |
| Temporary failures create an infinite hot loop                         | Persist attempts and next-attempt timestamps; fixed bounded retry schedule.                                                                           |
| A template change silently drops alerts                                | Envelope filter remains broad enough for configured alerts; raw messages still pass Phase 1 validation and quarantine; health degrades on quarantine. |
| Redirect-only alerts omit an offline canonical job identifier          | Quarantine as `email.missing_job_id`, persist no tracking value, and report reduced source coverage in soak evidence.                                 |
| Fixed-price alerts enter the candidate folder                          | Quarantine as `email.unsupported_contract_type`; fixed-price normalization remains explicitly outside Phase 2.                                        |
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
- The accepted IMAP compatibility proof and 24-hour staging soak meet every exit criterion; redirect HTTP compatibility is not required.
- No raw mail, personal mailbox data, secrets, or unsafe exception text is retained or exposed.
- ADR-004 and the mailbox runbook are committed.
- `project_sheet.md` is updated from draft to the audited as-built implementation.
- `PROJECT_ROADMAP.md` marks Phase 2 complete only after deployment verification.

## Phase 3: Explainable Triage and Feasibility Gate

**Role:** As-built implementation specification for the current phase
**Status:** Implementation deployed; first real calibration READY but targets not met; no GO decision claimed
**Date:** 2026-09-14
**Repository destination:** `.github/docs/project_sheet.md`
**Behavior specification:** `.github/docs/features/triage_imported_opportunities.feature`

### Accepted baseline and scope decision

Use [the accepted Phase 2 baseline, `40af4b5`](https://github.com/KontentWave/freelance-opportunity-triage-platform/tree/40af4b51b31e797b9707444c38d0cb590f2cd823). PHP 8.4, Laravel 13, MariaDB 11.4, the existing normalized opportunity contract, workspace ownership, and required CI checks remain the baseline. Prior as-built details remain available in Git history when this current-phase sheet replaces the active file.

Marcel accepted the published Phase 2 implementation as complete for the portfolio demo on 2026-09-08. Further database deadline hardening is deferred. The existing implementation does not establish the previously claimed absolute database runtime guarantee. Correct that claim in the roadmap/ADR documentation when adopting this sheet; this is documentation reconciliation, not a new Phase 2 engineering prerequisite.

Phase 3 follows the roadmap's deterministic scoring and calibration hypothesis. It adds no mailbox changes, new transport work, scheduler integration, UI, HTTP API, external requests, LLM service, queue, or new runtime/package dependency. The reusable Tester Skill and full-description enrichment remain outside this phase.

### Action

Classify an already imported opportunity as **APPLY**, **MAYBE**, or **SKIP**, explain the result from a versioned profile and saved inputs, and compare those suggestions with human judgments to decide whether a review dashboard is worth building.

These are alert-triage labels. APPLY means “prioritize reviewing this opportunity for a possible application.” MAYBE means “retain for manual review.” SKIP means “suggest deprioritizing”; it never deletes or hides the underlying record. Every result has `manual_review_required=true` and the same reminder to verify full scope, credible delivery capability, and weekly hours before applying.

The tool cannot establish Marcel's independent delivery capability from an email summary. His availability is at most **20 hours/week**; the existing `estimated_duration` field is a project duration, not weekly hours. Do not infer hours, specialist fluency, required credentials, or suitability from absent data. Exact skill matching is one preference signal, not proof of qualification or a hard exclusion.

### Deliverable boundary

- One pure scorer with **four fixed rules**, configurable weights and thresholds, and one confirmed rate exclusion.
- Local JSON profiles; their canonical content hash is their immutable version. No profile-management database or rule-language framework.
- Two new tables: evaluations and the current human review of each evaluation.
- Three operator CLI commands: evaluate one opportunity, record/revise a human judgment, and report a selected calibration cohort.
- Synthetic fixtures and MariaDB tests; one small real-data calibration exercise after implementation.

### Scoring profile contract

Add `resources/triage/profiles/demo-v1.json` containing this **synthetic demonstration configuration**. These amounts, skills, weights, and cutoffs are not asserted to be Marcel's actual preferences. Real calibration requires an explicitly supplied personal profile and human labels.

```json
{
    "schema_version": 1,
    "label": "Synthetic demo profile",
    "purpose": "demo",
    "minimum_hourly_usd": "20.00",
    "preferred_skills": ["django", "project management", "quality assurance"],
    "minimum_client_rating": "4.50",
    "weights": {
        "skill_match": 40,
        "rate": 30,
        "payment_verified": 20,
        "client_rating": 10
    },
    "thresholds": { "skip_below": 35, "apply_at": 70 }
}
```

Validate the entire profile before querying or writing opportunities. Accept only the documented keys and schema version 1. `purpose` is `demo` or `personal`; `label` is a non-empty string of at most 80 characters. The USD floor is a positive two-decimal string fitting the existing `decimal(10,2)` rate range; the rating threshold is a two-decimal string greater than zero and at most `5.00`. Each of the four weights is an integer from 1 to 100 and their sum is exactly 100. Thresholds are integers satisfying `0 <= skip_below < apply_at <= 100`. Require 1–50 unique normalized preferred skills, each at most 100 characters. Invalid profiles produce `triage.profile_invalid` and no evaluation write.

Normalize skill strings by trimming, collapsing Unicode whitespace to one space, and applying `mb_strtolower`; discard empty input skill strings and deduplicate/sort skill lists with a stable string comparison. Reject empty preferred-skill entries in a profile. Match entire normalized skill names, not substrings, synonyms, titles, or excerpts.

Canonical JSON recursively sorts object keys, preserves scalar types, uses normalized skill-list ordering and two-decimal monetary/rating strings, and excludes timestamps. Encode without insignificant whitespace using unescaped Unicode and slashes. `profile_version = sha256(canonical profile JSON)`. Persist that full normalized JSON alongside the hash. Formatting-only JSON changes preserve the version; a changed normalized definition gets a new version automatically. Use `engine_version = "triage-v1"`; change it if scoring semantics change later. No timestamps, current exchange rates, randomness, or network data affect scoring.

### Input and rule contract

Build an immutable input snapshot from the workspace-scoped `Opportunity` and its `skills` relation. Include only `contract_type`, `currency`, `hourly_max`, `skills`, `hidden_skill_count`, `payment_verified`, and `client_rating`. Preserve nullable decimal strings and genuine booleans. Normalize skills as above and `currency` to uppercase. Hash the canonical snapshot as `input_sha256`.

Do not duplicate title, excerpt, URL, email data, or other unused fields into evaluation snapshots. Existing opportunity rows remain the source for those fields. Country, past client spend, project duration, and the absence of an exact specialist technology are not hard filters in this first version.

Evaluate every rule in the following order, even when a hard exclusion applies. A rule returns `matched`, `not_matched`, or `unknown`, plus its integer contribution, maximum contribution, stable reason code, and a plain-language explanation.

| Rule               | Matched: full configured weight                                               | Not matched: zero points                                       | Unknown: zero points                                                     |
| ------------------ | ----------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `skill_match`      | At least one visible normalized skill matches a profile preference            | Non-empty visible skills, no match, and `hidden_skill_count=0` | No visible skills, or no match while additional skills are hidden        |
| `rate`             | Hourly USD job with a positive known maximum at or above the configured floor | Hourly USD job with a positive known maximum below the floor   | Missing/non-positive maximum, non-USD currency, or a non-hourly contract |
| `payment_verified` | Exactly `true`                                                                | Exactly `false`                                                | `null`                                                                   |
| `client_rating`    | Positive known rating at or above the threshold                               | Positive known rating below the threshold                      | `null` or `0.00` (unrated)                                               |

A range whose lower bound is below the floor but whose maximum meets it passes the rate rule: the advertised ceiling permits the requested floor. This is not a guarantee of the client's offered rate. Do not do currency conversion. Existing `$0–$0` normalization already yields unknown rates.

Use reason codes `skills.match`, `skills.no_match`, `skills.unknown`; `rate.meets_floor`, `rate.below_floor`, `rate.unknown`; `payment.verified`, `payment.unverified`, `payment.unknown`; and `rating.meets_minimum`, `rating.below_minimum`, `rating.unknown`. A confirmed below-floor rate additionally yields the hard-exclusion code `rate.maximum_below_minimum`.

`missing_fields` is a sorted unique list. A skill unknown adds `skills`; payment/rating unknowns add their field names. A rate unknown adds whichever of `hourly_max`, `currency`, and `contract_type` caused it. Unknown means insufficient evidence, not an adverse fact.

Compute `score` as the sum of the four contributions; never normalize by only the known rules. Apply this precedence:

1. Confirmed hard exclusion → **SKIP**, regardless of score or other missing fields.
2. Otherwise any unknown rule → **MAYBE**, regardless of score.
3. Otherwise `score >= apply_at` → **APPLY**; `score < skip_below` → **SKIP**; all remaining scores → **MAYBE**.

Decision reason codes are respectively `decision.below_rate_floor`, `decision.incomplete_data`, `decision.score_apply`, `decision.score_skip`, and `decision.score_maybe`. A known skill mismatch alone cannot force SKIP with the demo profile: all other positive signals give 60 points and MAYBE. Missing information alone cannot force SKIP under any valid profile.

### Result, persistence, and workspace isolation

`TriageResult` contains `recommendation`, `score`, the ordered four `contributions`, `missing_fields`, `hard_exclusions`, `decision_reason_code`, a plain-language decision explanation, and `manual_review_required=true`. Each contribution contains `rule`, `state`, `points`, `maximum_points`, `reason_code`, and `explanation`.

Use ULIDs and the repository's existing model/migration conventions:

| Table                     | Columns and invariants                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `opportunity_evaluations` | `id`; `workspace_id` and `opportunity_id` foreign keys; `engine_version` string(32); `profile_version` and `input_sha256` char(64); `profile_snapshot`, `input_snapshot`, and `result` JSON; `recommendation` string(8); `score` unsigned tiny integer validated 0–100; `created_at`. Unique `(workspace_id, opportunity_id, engine_version, profile_version, input_sha256)`. Stored recommendation/score must equal the JSON result. Evaluation rows are immutable through the application. |
| `opportunity_reviews`     | `id`; `workspace_id` and `evaluation_id` foreign keys; `human_label` string(8); `reason_code` nullable string(32); `sample_kind` string(8), `demo` or `real`; `reviewed_at`; ordinary timestamps. Unique `evaluation_id`. One current human review, explicitly replaceable through the review action.                                                                                                                                                                                        |

Use normal FK cascades for parent deletion, consistent with existing models; add no deletion command. Every lookup, write, and report must be scoped by the required workspace ID. Resolve the opportunity/evaluation within that workspace first, then derive child ownership from it. A foreign workspace's identifier is handled exactly like a nonexistent identifier with `triage.not_found`; no other workspace's data is returned or changed.

Repeat evaluation of the same opportunity, normalized inputs, profile version, and engine version returns the existing evaluation without updating it. Changed inputs or profile content create a separate row; older snapshots and reviews remain unchanged. Handle a concurrent duplicate insert by retrieving the matching scoped row. Write each new evaluation atomically.

The stored profile and input snapshots must reproduce an old result through the scorer even after the live opportunity or profile file changes. This is a testable domain capability; a separate replay command and a historical engine registry are unnecessary for this phase.

Human labels use the same APPLY/MAYBE/SKIP enum. If a label differs from the machine recommendation, require one reason code: `fit`, `availability`, `economics`, `client_risk`, `missing_information`, or `other`. Explain these codes in CLI help. Free-text descriptions are outside this slice. A repeated identical review leaves the row unchanged; an explicit changed review updates it. Neither operation changes the machine evaluation. `sample_kind=real` is an operator declaration of genuine alert provenance, not something inferred from an identifier.

### Classes and CLI boundary

Implement the following small set of components; keep profile JSON reading in the command boundary and scoring independent of Laravel/database/network state.

| Path                                                                       | Responsibility / callable contract                                                                                                                  |
| -------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Domain/Triage/Enums/TriageRecommendation.php`                         | Backed enum with values `APPLY`, `MAYBE`, `SKIP`.                                                                                                   |
| `app/Domain/Triage/Data/ScoringProfile.php`                                | Validated immutable profile, normalized array and content version; `fromArray(array $definition): self`.                                            |
| `app/Domain/Triage/Data/TriageInput.php`                                   | Immutable normalized input and canonical snapshot/hash.                                                                                             |
| `app/Domain/Triage/Data/TriageResult.php`                                  | Immutable result contract defined above.                                                                                                            |
| `app/Domain/Triage/OpportunityScorer.php`                                  | `evaluate(TriageInput $input, ScoringProfile $profile): TriageResult`.                                                                              |
| `app/Domain/Triage/CalibrationCalculator.php`                              | Pure aggregate calculations and evidence status from selected evaluations/reviews.                                                                  |
| `app/Application/Triage/EvaluateOpportunity.php`                           | `execute(string $workspaceId, string $opportunityId, ScoringProfile $profile): OpportunityEvaluation`; scoped load, snapshot, score, persist/reuse. |
| `app/Application/Triage/RecordOpportunityReview.php`                       | Scoped validation and explicit review create/update.                                                                                                |
| `app/Application/Triage/BuildCalibrationReport.php`                        | Scoped cohort loading and validation, then pure calculator; no report table.                                                                        |
| `app/Models/OpportunityEvaluation.php`, `app/Models/OpportunityReview.php` | Persistence, JSON casts, ownership and parent relations.                                                                                            |
| `app/Console/Commands/TriageOpportunityCommand.php`                        | Evaluate one explicitly selected opportunity.                                                                                                       |
| `app/Console/Commands/ReviewOpportunityCommand.php`                        | Record an explicit human decision.                                                                                                                  |
| `app/Console/Commands/ReportOpportunityTriageCommand.php`                  | Report an explicit list of evaluations.                                                                                                             |

Command contracts:

```text
php artisan opportunity:triage {opportunity} --workspace=<ULID> --profile=<local-json-path> [--json]
php artisan opportunity:review {evaluation} {APPLY|MAYBE|SKIP} --workspace=<ULID> [--reason=<code>] [--sample-kind=demo|real] [--json]
php artisan opportunity:triage-report --workspace=<ULID> --evaluations=<local-json-path> [--json]
```

`--workspace` and `--profile` are required where shown; there is no implicit global or personal profile. The review command defaults to `sample-kind=demo`. Profile and cohort files must be readable local regular files, at most 64 KiB, decoded as JSON; reject URLs/stream wrappers. A cohort file is a JSON array of 1–100 distinct evaluation ULIDs. Require one profile version and one engine version and at most one evaluation per opportunity in that cohort. Mixed versions or repeated opportunities produce `triage.cohort_invalid` before producing a report. Never select only machine-SKIP rows automatically.

Successful commands return exit code 0 even for SKIP or an insufficient-data report; those are domain outcomes. Invalid input or an operational failure returns 1 with one of `triage.profile_invalid`, `triage.not_found`, `triage.review_invalid`, `triage.cohort_invalid`, or `triage.operation_failed`. Centralize that allowlist; do not print caught exceptions, private file contents, or paths.

Evaluation output includes evaluation/opportunity IDs, profile and engine versions, result and explanations; review output includes its ID, evaluation ID, human label, reason code, and sample kind. Report output is aggregate only. `--json` emits one object with stable keys and no progress chatter. Default text output must be readable without color and include the full-scope/capability/20-hour reminder. CLI execution assumes a trusted server operator; this phase adds no unauthenticated web route or API.

### Calibration and product decision

After the synthetic demo works, select **at least 30 distinct genuine, successfully normalized alerts** from a consecutive, predetermined intake window. Keep all selected opportunities regardless of the machine label. Explicitly supply a personal profile and record a human label for each. Marcel may inspect his own screenshots/pasted text or manually view the listing to judge full suitability; the application performs no such retrieval and stores only the label/reason code. Where practical, label before viewing the machine suggestion.

The denominator is this selected supported-import cohort. Redirect-only, fixed-price, quarantined, or unimported alerts are outside it. Therefore the measured elimination rate is a **suggested reduction in opens for supported imports**, not demonstrated marketplace-wide coverage or measured time savings. The report must state this scope.

For skip-vs-review metrics, both APPLY and MAYBE count as **keep for review**. Keep the full 3×3 machine/human confusion matrix as well.

| Metric                             | Exact definition                                           |
| ---------------------------------- | ---------------------------------------------------------- |
| `true_positive` / `false_positive` | Machine keeps, human keeps / machine keeps, human SKIPs.   |
| `true_negative` / `false_negative` | Machine SKIPs, human SKIPs / machine SKIPs, human keeps.   |
| `skip_rate_percent`                | `100 * (true_negative + false_negative) / N`.              |
| `false_negative_rate_percent`      | `100 * false_negative / (true_positive + false_negative)`. |
| `false_positive_rate_percent`      | `100 * false_positive / (true_negative + false_positive)`. |

Report `n_selected`, `n_reviewed`, versions, sample-kind counts, confusion matrix, the four counts, the three rates, counts of each missing field, disagreement-reason counts, `data_status`, and `targets_met`. Percentages display two decimals; an undefined denominator yields `null`, never zero. If some selected rows lack reviews, all three rates and `targets_met` are null and the report identifies the incomplete count.

- `DEMO_ONLY`: the profile purpose is demo or any selected review is demo; `targets_met=null` regardless of sample size. Synthetic data never establishes product success.
- Otherwise `INSUFFICIENT_DATA`: fewer than 30 selected alerts, incomplete reviews, or no human-keep or no human-SKIP examples; `targets_met=null`. Complete smaller cohorts may show descriptive rates.
- Otherwise `READY`: all selected reviews are real and complete, both human classes exist, and N is at least 30. Set `targets_met` using unrounded integers: `2 * (TN + FN) >= N` and `20 * FN <= TP + FN`.

For READY reports, true supports proceeding to dashboard work; false calls for **one** deliberate profile/targeting revision and another reported calibration. If that still fails, stop or reposition before expanding the product. Record that project decision in the roadmap; do not build a tuning workflow. Thirty alerts are a small feasibility sample, not proof of a population-wide accuracy guarantee. No synthetic results or uncollected real labels may be described as successful calibration.

### Ordered implementation tasks

1. **Pure behavior first.** Read applicable repository rules; write the profile/scorer tests and implement the fixed contracts, canonicalization, demo JSON, and golden cases under `tests/Fixtures/Triage/`. Record the bounded design and accepted Phase 2 scope in `.github/docs/adr/ADR-005-deterministic-opportunity-triage.md`.
2. **One complete CLI evaluation path.** Add migrations named `create_opportunity_evaluations_table` and `create_opportunity_reviews_table`, the models, scoped evaluate action, and triage command. Prove repeat evaluation, changed versions/inputs, and workspace isolation against MariaDB. Keep all prior import/mailbox behavior unchanged.
3. **Human feedback and feasibility evidence.** Add review and report actions/commands, pure calibration math and their tests. Document a synthetic CLI walkthrough and private personal-profile/cohort usage in `README.md`; keep real files under ignored `storage/app/private/triage/`. Extend the existing CI coverage check to enforce at least 90% for `app/Domain/Triage/` while preserving the separate existing 90% parser/domain and 80% overall gates. Finish as-built docs and the actual calibration decision when evidence exists.

These are three implementation slices, not three new phases. Review each coherent slice using the existing Scrum-XP loop. No additional generic infrastructure work is part of acceptance.

## Test plan and behavior traceability

The repository uses PHPUnit rather than a Gherkin runner. Keep the `.feature` file as the behavior contract and make these mapped PHPUnit cases its executable validation. No Behat dependency is required. All “real-mode” test records remain synthetic test data; they test report logic only.

| Gherkin scenario                                                    | Primary PHPUnit case                                                |
| ------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Explain a promising imported opportunity                            | `it_explains_a_promising_opportunity_with_four_contributions`       |
| Reject a confirmed rate ceiling below the configured minimum        | `it_applies_a_known_rate_exclusion_before_missing_data`             |
| Keep incomplete evidence for manual review                          | `it_keeps_unknown_signals_as_maybe_without_a_hard_exclusion`        |
| Treat a skill mismatch as a preference signal                       | `it_does_not_skip_only_because_visible_skills_do_not_match`         |
| Apply deterministic score thresholds to complete evidence           | `it_applies_score_thresholds_after_exclusions_and_unknowns`         |
| Reject an invalid scoring profile without writing                   | `it_rejects_invalid_profiles_before_evaluation_writes`              |
| Reuse an identical evaluation and preserve older versions           | `it_reuses_identical_evaluations_and_preserves_prior_snapshots`     |
| Record and revise human judgment independently                      | `it_records_human_feedback_without_changing_the_machine_result`     |
| Isolate evaluation, feedback, and reports by workspace              | `it_rejects_foreign_workspace_ids_without_disclosure_or_writes`     |
| Prevent synthetic or insufficient evidence from passing calibration | `it_never_passes_calibration_without_sufficient_real_labels`        |
| Calculate a real-mode calibration report at the target boundary     | `it_calculates_calibration_counts_and_unrounded_targets`            |
| Reject a cohort that could distort comparison                       | `it_rejects_mixed_versions_and_duplicate_opportunities_in_a_cohort` |

Use `tests/Unit/Domain/Triage/ScoringProfileTest.php`, `OpportunityScorerTest.php`, and `CalibrationCalculatorTest.php` for pure logic. Use `tests/Feature/EvaluateOpportunityTest.php`, `RecordOpportunityReviewTest.php`, `OpportunityTriageReportTest.php`, and `OpportunityTriageCommandTest.php` for persistence and CLI paths. Factories create the actual MariaDB records; do not mock Eloquent queries.

Also cover normalized JSON/hash equivalence; both score-threshold equalities; zero/unrated client rating; hidden-skill uncertainty; non-USD/non-hourly rate uncertainty; old-result reproduction after live-row mutation; atomic duplicate handling; review reason validation; null denominators; incomplete reviews; calibration with a skip rate below 50% despite zero false negatives; and safe JSON/error output. The CLI smoke must prove triage/review/report do not resolve the mailbox client or make an HTTP request. Do not copy the scoring formula into assertions to compute their expected results.

### Privacy, accessibility, and operational limits

Use only the existing normalized inputs and explicit local profile/cohort files. No raw email, headers, recipient addresses, credentials, tracking values, or full descriptions belong in new tables, fixtures, logs, or reports. Use stable errors and bounded inputs. Existing workspace and foreign-key safeguards remain required. Real opportunity/profile/review data remains private; publish only synthetic demonstrations and safe aggregate evidence.

There is no graphical UI in Phase 3. Use text labels, plain reasons, accessible command help, and JSON output. Presentation must keep machine suggestions distinct from human judgments.

This is explicit, small-cohort CLI processing. It is not automatically invoked by imports or cron. A synthetic CLI smoke on the deployment is sufficient new operational verification; no repeated mailbox soak or absolute-runtime engineering gate is introduced. Roll back by stopping use of the triage commands and reverting compatible Phase 3 code; preserve evaluations/reviews and leave the Phase 1/2 data and commands available. Do not run destructive migrations as an operational rollback.

### Ready, Done, and evidence status

**Ready:** this sheet and its feature file define the current slice; the accepted Phase 2 baseline is sufficient. The demo profile makes initial implementation possible without collecting personal thresholds or 30 real alerts first.

**Implementation done:** the three implementation slices now provide the pure profile/scorer/calibration domain, immutable workspace-owned evaluations, explicit current reviews, scoped cohort reporting, and the three local operator commands. Review submissions validate labels, reasons, and sample provenance; identical submissions are no-ops and explicit changed submissions revise only the human review. Cohorts are explicit bounded local JSON arrays and reject duplicate IDs, mixed engine/profile versions, repeated opportunities, absent or foreign evaluations, and malformed input. Reports are aggregate-only and use integer target boundaries.

Executed local evidence at this update: the reviewed Phase 3 PHPUnit surface passed against MariaDB 11.4 with 41 tests and 198 assertions. The final complete MariaDB suite passed with 150 tests and 1,085 assertions. PHPStan, Pint, strict Composer validation, workflow YAML parsing, and the locked dependency audit passed; the audit found no security advisories. Local coverage was not measured because this PHP installation has neither PCOV nor Xdebug.

Protected CI passed for commit `240f20f` in [run 34523147987](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/34523147987): `Quality`, `Tests / MariaDB 11.4`, and `Secret scan` all succeeded. The hosted suite passed with 150 tests and 1,085 assertions. Measured statement coverage was 89.97% overall, 93.67% for the Phase 1 parser/domain slice, and 96.62% for `app/Domain/Triage/`, satisfying the respective 80%, 90%, and 90% gates. Composer validation, Pint, PHPStan, the locked dependency audit, and secret scanning also passed with no advisories or leaks found.

**Phase 3 feasibility decision:** record a complete READY report and the resulting GO or stop/reposition decision. If choosing the one allowed revision, record its rerun before closing this checkpoint. Target failure can be a valid completed experiment; it does not justify pretending the hypothesis passed. If real labels are unavailable, label the state “implementation complete; real calibration pending” and keep product feasibility unclaimed. Gathering that evidence does not block implementation of this specified slice.

**Operational calibration status (2026-09-14):** Phase 3 is deployed at merge commit `2957eed9e6275580a4419a3e55d4e2b327a19f49`; its additive migrations are applied, and the deployed synthetic triage/review/report smoke passed with `DEMO_ONLY` status. A validated personal-purpose profile and the real worksheet/cohort files exist only in private, Git-ignored production storage with mode `0600`. No private profile values, identifiers, labels by opportunity, or opportunity data are published here.

The predetermined consecutive cohort is complete with 30 distinct genuine supported imports. All 30 human judgments were recorded before machine scoring, then evaluated with engine `triage-v1` and fixed profile version `60488589fa869075c7652e480a575b45f06ce5f2384150494c262eb66d2bfcaf`; all 30 reviews are marked `real`. Human labels were 5 APPLY, 6 MAYBE, and 19 SKIP. Machine labels were 8 APPLY, 21 MAYBE, and 1 SKIP. The machine-by-human confusion matrix was APPLY `{APPLY: 4, MAYBE: 1, SKIP: 3}`, MAYBE `{APPLY: 1, MAYBE: 4, SKIP: 16}`, and SKIP `{APPLY: 0, MAYBE: 1, SKIP: 0}`. The aggregate report is `READY` with 30 selected, 30 reviewed, and no incomplete rows. It measured a 3.33% machine skip rate, a 9.09% false-negative rate, and a 100.00% false-positive rate (`TP=10`, `FP=19`, `TN=0`, `FN=1`). Missing-field counts were client rating 3, hourly maximum 7, payment verification 3, and skills 9. Disagreement reasons were availability 4, fit 15, and missing information 3.

The required skip rate of at least 50% and false-negative rate of at most 5% were both missed, so `targets_met=false`. This first real calibration does not support proceeding on a product-feasibility GO claim. Per the fixed experiment contract, the next product decision is either one deliberate profile/targeting revision followed by one new reported calibration, or stop/reposition before product expansion. No scoring change or second calibration is claimed here.

### Sources used

- [Phase 3 roadmap and success signals at the accepted baseline](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/40af4b51b31e797b9707444c38d0cb590f2cd823/.github/docs/PROJECT_ROADMAP.md).
- [Opportunity model](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/40af4b51b31e797b9707444c38d0cb590f2cd823/app/Models/Opportunity.php), [existing schema](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/40af4b51b31e797b9707444c38d0cb590f2cd823/database/migrations/2026_08_27_145315_create_opportunities_table.php), and [CI gates](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/40af4b51b31e797b9707444c38d0cb590f2cd823/.github/workflows/ci.yml).
- Supplied `Scrum-XP.md` and `scrum_xp_expanded_unified_dev_ops_guide.md`: Action → ordered tasks → named tests/Gherkin → audit and evidence; superseded scope assumptions follow Marcel's explicit demo-scope decision in this conversation.

---

## Phase 4: Accessible Review Dashboard and Manual Enrichment

**Role:** Approved implementation contract; append this section to the existing as-built sheet.
**Status:** Complete. All three implementation slices passed protected CI and the isolated synthetic deployment passed the approved target-host acceptance gate. Phase 3 engineering and its unsuccessful first real calibration remain accepted historical evidence.
**Date:** 2026-09-22
**Behavior file:** `.github/docs/features/review_and_enrich_opportunities.feature`

### Evidence and entry decision

Reviewed repository baseline: [main at ff499f3](https://github.com/KontentWave/freelance-opportunity-triage-platform/tree/ff499f3f30b71ba4fef647a426a0b61cfcf4a6dd), including [green CI run 34617521736](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/34617521736). This is a documentation update over the accepted Phase 3 implementation at `2957eed`.

The Phase 3 operational record says the deployed synthetic smoke passed and the first private real calibration completed with 30 selected and reviewed opportunities. Its `READY` report measured a 3.33% skip rate and 9.09% false-negative rate, so `targets_met=false`. There is no calibration-based GO decision. Deployment verification and the Phase 3 engineering work remain complete; the feasibility hypothesis failed this first real test.

Marcel explicitly decided on 2026-09-14 to proceed only as a portfolio demonstration despite the failed feasibility targets. This authorized implementation without another calibration cycle and did not convert the failed experiment into a feasibility GO. The protected read journey, enrichment/feedback, guarded synthetic demo, local browser acceptance, and target-host acceptance are complete.

### Protected read journey evidence

Local Slice 1 validation completed on 2026-09-15 against the disposable MariaDB 11.4 test database. The complete suite passed with 159 tests and 1,172 assertions. PCOV 1.0.12 measured 90.29% overall statement coverage (2,017/2,234), 93.67% Phase 1 parser/domain coverage (222/237), and 96.62% triage-domain coverage (343/355), satisfying the existing 80%, 90%, and 90% gates.

Pint, PHPStan, strict Composer validation, the locked Composer audit, `npm ci`, the Vite production build, the npm audit, and `git diff --check` passed. Composer and npm reported no known dependency vulnerabilities. A local synthetic browser smoke verified private login, ranked queue and detail navigation, explicit evaluation, persisted status after reload, logical keyboard operation, polite status output, and no page-level horizontal overflow at a 320 CSS-pixel viewport. It made no mailbox or marketplace request.

Candidate commit [`83821d1`](https://github.com/KontentWave/freelance-opportunity-triage-platform/commit/83821d17a870b40d47e8cf6f7528c60bfaa2e249) passed the protected pull-request checks in [CI run 35006230737](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/35006230737): Quality, Tests / MariaDB 11.4, and Secret scan. The local Node runtime was 22.18.0, below the declared `>=22.23` project baseline; `npm ci` emitted `EBADENGINE`, although installation and the production build succeeded. Target-host verification, enrichment/feedback acceptance, synthetic demo acceptance, and the final Playwright suite are not claimed by this Slice 1 evidence.

### Enrichment and feedback evidence

Local Slice 2 validation completed on 2026-09-17 against the disposable MariaDB 11.4 test database. The complete suite passed with 169 tests and 1,314 assertions. PCOV measured 90.61% overall statement coverage (2,276/2,512), 93.67% Phase 1 parser/domain coverage (222/237), and 96.90% triage-domain coverage (344/355), satisfying the existing 80%, 90%, and 90% gates.

All seven named Slice 2 PHPUnit cases passed, covering independent feedback, email-only calibration, description-only enrichment, confirmed-field scoring, revision/idempotency behavior, stale contexts, and unsafe or excessive input. Additional executable coverage passed for guest and unassigned access, workspace and nested-resource isolation, sparse omitted/null/false/empty override semantics, current-review filtering, enriched queue display/filter/order, rollback, escaping, and stray HTTP prevention. Existing Phase 3 command and calibration behavior remained compatible.

Pint, PHPStan, strict Composer validation, the locked Composer audit, `npm ci`, the Vite production build, the npm audit, and `git diff --check` passed. Composer and npm reported no known dependency vulnerabilities. The local Node runtime remained 22.18.0, below the declared `>=22.23` project baseline; `npm ci` emitted `EBADENGINE`, although installation and the production build succeeded.

A local synthetic private browser smoke verified keyboard login and navigation, confirmed-detail enrichment, feedback persistence, text-only rendering of script-like input, same-origin resources, logical keyboard reachability at simulated 200% zoom, and no page-level horizontal overflow at a 320 CSS-pixel viewport. A recoverable invalid enrichment save returned 422, retained the draft, focused the error summary, and left no stale success announcement. This was a manual synthetic smoke, not the Slice 3 Playwright acceptance suite. No target-host verification, synthetic demo seeder or preset flow, credential-free demo entry, runbook, deployment, or final browser acceptance is claimed. Phase 4 remains incomplete.

Post-completion correction work on 2026-09-22 aligned dashboard writes with the effective evaluation already selected by reads: a valid pointer remains preferred, otherwise the newest active-profile/engine evaluation is selected by creation time and ID without mutating the pointer. Enrichment and feedback accept that displayed context while retaining 409 responses for different historical evaluations. Identical CLI and dashboard feedback retries now compare normalized scalar values and leave every persisted attribute unchanged after time advances, including `reviewed_at` and `updated_at`. This correction does not replace or revise the historical CI and target-host evidence below.

### Demonstration and acceptance evidence

Local Slice 3 implementation added a guarded, idempotent `ReviewDemoSeeder` with two assigned synthetic workspaces, 28 fixed opportunities, all recommendation states plus UNSCORED and edge states, and no mailbox or marketplace access. The seeder accepts only demo mode on a disposable `_demo` or `_test` database with mailbox intake disabled, refuses unrelated application records, and does not update existing fixture rows.

Demo entry is a credential-free CSRF-protected POST for the configured seeded user only. A middleware gate returns 404 for both demo routes in private mode before CSRF processing. Shared-demo enrichment resolves allowlisted preset keys on the server; arbitrary descriptions and overrides are rejected. Feedback accepts only its enums and an empty or fixed synthetic note, and real provenance is rejected while the application forces `sample_kind=demo`.

Local MariaDB validation passed for `ReviewDemoTest` with 5 tests and 29 assertions. The production Vite build passed. The Chromium-only Playwright suite passed 2 tests sequentially with one worker against compiled assets and the disposable MariaDB `_test` database. It covered private-mode demo rejection and unauthenticated JSON access; keyboard entry/navigation; 26-record workspace isolation, direct foreign-record denial, and pagination boundary; 320 CSS pixels and simulated 200% zoom; script-like text escaping; same-origin request monitoring; forged CSRF rejection with no mutation; recoverable 422, 409, and 401 saves; draft preservation and focused errors; preset-only demo controls; persistence after reload; and no outside-origin request.

The existing `Tests / MariaDB 11.4` job now pins Node 22.23.0, runs `npm ci`, builds production assets, audits npm dependencies, installs Chromium, and runs the one-worker browser suite after the existing PHPUnit coverage gates. PR #11 merged as commit [`906586d`](https://github.com/KontentWave/freelance-opportunity-triage-platform/commit/906586d77abad2b383306cbd89cfa4382c7c6faa). Its post-merge [CI run 35434572184](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/35434572184) passed `Quality`, `Tests / MariaDB 11.4`, and `Secret scan`.

### Target-host deployment and acceptance evidence

Candidate [`65bf4ea`](https://github.com/KontentWave/freelance-opportunity-triage-platform/commit/65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c) was deployed on 2026-09-22 using a separate disposable MariaDB 11.4 database ending in `_demo`. Production configuration resolved to demo mode with mailbox intake disabled and the bundled synthetic profile. The guarded `ReviewDemoSeeder` ran after `migrate:fresh`; locked production dependencies and compiled Vite assets were deployed. The resulting database contained 2 synthetic workspaces, 2 synthetic users, 28 synthetic opportunities, 27 evaluations, 3 enrichment revisions produced by acceptance retries, and 2 demo reviews. Email-import and mailbox tables contained zero records; every opportunity used the synthetic provider and every review retained demo provenance.

The public target-host browser smoke passed credential-free demo entry; a 25-item first page from the assigned 26-opportunity workspace; direct foreign-workspace detail denial with 404; keyboard queue-to-detail navigation; 320 CSS-pixel and simulated 200% zoom layouts without page-level horizontal overflow; a focused recoverable 422 with its preset draft preserved; confirmed-field re-evaluation; feedback save and persistence after reload with `sample_kind=demo`; and logout followed by a 401 JSON access check. Browser request capture observed zero mailbox, marketplace, or non-origin requests. The deployed worktree remained tracked-clean at `65bf4ea`.

### Action and bounded scope

Let the workspace owner review ranked opportunities in a browser, understand each suggestion, confirm additional facts from manually supplied details, and record a human decision and outcome.

Deliver **two application screens**: a review queue and an opportunity detail page. Include the login/logout needed to protect private records, and a credential-free entry to a separately configured synthetic demo. Reuse PHP 8.4, Laravel 13, MariaDB 11.4, the Phase 3 scorer and review action, Vite 8 and Tailwind 4. Add Vue 3 single-file components within the existing Laravel application; use normal Laravel page navigation and same-origin JSON requests.

No SPA router, state-management package, admin framework, public registration, billing, external API, file upload/OCR, automatic text extraction, profile editor, marketplace automation, new mailbox behavior, background scoring, or new scoring rule is required. Phase 5 owns release packaging and publication. This phase's passing demo smoke does not claim a public release or validated time savings.

### Minimal application and access boundary

The inspected application currently has a welcome route, a User model and a Workspace model, but no login flow, user-to-workspace assignment, or Vue application. Implement these explicit prerequisites:

- Add nullable `users.workspace_id`, a foreign ULID referencing workspaces with null-on-delete. Keep User IDs as the existing bigint. Add `User::workspace()`. An operator assigns an existing private workspace through trusted Laravel tooling; never infer ownership from email addresses or assign an existing workspace automatically.
- One assigned workspace per web user is sufficient. Every page, JSON query and mutation derives that workspace from the authenticated user. A client cannot select another workspace through a path, body, query string or header.
- Use Laravel's existing session authentication for an operator-provisioned email/password account. Regenerate the session on successful login; invalidate it and regenerate its CSRF token on logout. Use generic failed-login text, a five-attempt-per-minute limiter keyed by normalized email plus IP, and the framework's CSRF protection. No password, request body or profile path belongs in logs.
- Add `config/opportunity_review.php`: `mode` is `private` by default or explicitly `demo`; `profile_path` is a server-controlled local path in private mode; `demo_user_id` identifies only the seeded demo account. Read environment values here. Blank/invalid private profile configuration produces a safe 503 response, never fallback to the demo profile.
- Extract the existing bounded local-profile reader from `TriageOpportunityCommand` into `app/Infrastructure/Triage/LocalScoringProfileLoader.php`. Preserve its CLI errors and 64 KiB/local-file contract. The browser never supplies a path or a profile definition. One active profile per deployment is enough for this personal-tool slice.
- Use `EnsureReviewWorkspace` middleware and `OpportunityPolicy` for access. Resolve nested evaluations and enrichments under the authorized opportunity. Foreign and absent resource IDs both return 404; an authenticated user with no assigned workspace gets a generic 403 setup message.

This uses the framework's existing authentication facilities, with no new PHP auth package. See [Laravel authentication](https://laravel.com/framework/docs/13.x/authentication).

### Queue, ranking and current evidence

The queue defaults to all opportunities; SKIP records remain accessible. Return 25 records per page. Apply filters and sorting in MariaDB before pagination, with eager loading for the displayed records.

Add nullable `opportunities.review_evaluation_id`, a foreign ULID to email evaluations with null-on-delete. Only a scoped server action sets this pointer. For each opportunity, use that selected evaluation when it belongs to the opportunity and matches the active profile/current engine; otherwise select the newest matching email evaluation, ordered by `created_at DESC, id DESC`. Phase 3 evaluation records retain their original meaning. If none exists, show **UNSCORED**, no score, and an Evaluate action. Do not silently score during a GET request.

For an evaluated opportunity, select the highest enrichment revision attached to that evaluation, if any. Its result is the displayed suggestion; otherwise display the email evaluation. Label the basis visibly as **Email evidence** or **Your confirmed details**. Keep the original email suggestion available on the detail page.

Sort by UNSCORED first, then APPLY, MAYBE, SKIP; within a scored group use score descending, posted date descending with null dates last, then opportunity ID ascending. UNSCORED uses the same date/ID tie-break. This ordering deliberately brings new work to attention.

Allow only these query filters:

| Parameter        | Allowed values / default                                 |
| ---------------- | -------------------------------------------------------- |
| `recommendation` | `ALL`, `UNSCORED`, `APPLY`, `MAYBE`, `SKIP`; default ALL |
| `review`         | `all`, `unreviewed`, `reviewed`; default all             |
| `missing`        | `all`, `present`, `none`; default all                    |
| `page`           | Positive integer; default 1; page size fixed at 25       |

For missing-data filtering, UNSCORED matches neither present nor none because it has no result. A review is current only when it targets the displayed base evaluation and its `enrichment_id` matches the displayed enrichment, or both are null. Older feedback remains stored, but the changed context requires confirmation again.

Preserve filters in the URL and when returning from details; changing a filter resets page to 1. Empty results explain that no jobs match and provide Clear filters. Queue rows show title, rate/unknown, posted date, suggestion, score, basis, missing-data indicator and human-review status.

The displayed score is a saved snapshot. Compute a stale flag for displayed rows and details by comparing the current normalized seven-field input hash with the base evaluation's input hash. Reuse one application-layer input mapper, `BuildOpportunityTriageInput`, extracted from `EvaluateOpportunity`; keep the domain independent of Eloquent. A stale score stays visibly marked and must be refreshed before new enrichment or feedback. A profile change makes records without an evaluation under that profile UNSCORED. Never rewrite old results or automatically carry manual overrides onto a new base evaluation.

### Detail page and manual enrichment

Show the title, original excerpt, normalized terms, client signals and visible/hidden skills. Use explicit Unknown/Unrated labels. Show all four contribution states, points, maximum points, reasons, missing fields, hard exclusions and profile label/version. Retain the manual scope/capability/20-hours-per-week reminder; project duration is not weekly hours.

Offer a canonical listing link only when it is HTTPS on `www.upwork.com` with path `/jobs/~<digits>` and no query/fragment. It opens only after a user click, with `rel="noopener noreferrer"`. No preview, prefetch, redirect resolution, remote image, or server request follows the link. Demo listing links are disabled.

**Enrichment is explicit data entry.** The owner pastes a full description as plain text and optionally confirms structured corrections. The application does not infer structured facts from prose. Explain this beside the form: pasted text supports human review; only confirmed fields affect the four-rule score.

The save payload is `{evaluation_id, expected_enrichment_id, full_description, overrides}`. IDs are scoped ULIDs; `expected_enrichment_id` is explicitly null when no enrichment has been shown. Normalize description line endings, trim outer whitespace, require 1–20,000 characters and cap the entire request body at 64 KiB. The sparse `overrides` object accepts only the seven existing input keys:

| Key                  | Explicit override validation                                                       |
| -------------------- | ---------------------------------------------------------------------------------- |
| `contract_type`      | `hourly` or null/unknown; other contract import types remain outside scope         |
| `currency`           | Three ASCII letters normalized uppercase, or null/unknown; no conversion           |
| `hourly_max`         | Null or nonnegative two-decimal string fitting decimal(10,2); zero remains unknown |
| `skills`             | 0–50 strings, each at most 100 characters after existing normalization             |
| `hidden_skill_count` | Integer 0–100; replacing visible skills does not silently clear it                 |
| `payment_verified`   | true, false or null; use a three-state control                                     |
| `client_rating`      | Null or two-decimal string between 0.00 and 5.00; zero means unrated               |

An omitted key inherits the base snapshot. Explicit null means unknown for nullable keys. Distinguish inherit/value/unknown in the form. No overrides means the score is unchanged even if the prose is extensive. Merge only validated keys over the base input, then call the existing `TriageInput` and `OpportunityScorer` using the **saved base profile snapshot**.

Add one table, `opportunity_enrichments`: ULID `id`; foreign `workspace_id`, `opportunity_id`, `evaluation_id`; unsigned `revision`; `full_description` text; `overrides`, `input_snapshot`, `result` JSON; `payload_sha256` and `input_sha256` char(64); `created_at`. Use parent cascades and unique `(evaluation_id, revision)`. The immutable base evaluation supplies its engine and profile snapshot, avoiding a second profile store.

Save in one transaction: lock the authorized opportunity, verify its current input and active base/profile, then lock the base evaluation and read its latest enrichment. Reject an unexpected current enrichment with 409. Evaluate, append revision 1 or latest+1, and return it. If the submitted normalized description/overrides equal the latest payload, return the latest record unchanged; allow that identical retry even if its expected ID is the preceding revision. A deliberate A → B → A edit creates a new revision. Derive ownership from the parent, never the body. Exceptions roll back all writes.

Old enrichment rows are immutable. A subsequent mailbox import may change the live opportunity, but leaves original email evaluations and enrichments untouched. On a fresh evaluation, old manual details are historical only. No automatic copy/replay or historical-engine registry is required.

### Human feedback and calibration separation

Use the existing `opportunity_reviews` record for the base email evaluation. Add nullable `enrichment_id` referencing the chosen enrichment, nullable `notes` text limited to 2,000 characters, and nullable `outcome` string(16). Outcomes are `not_applied`, `applied`, `in_discussion`, `hired`, `closed`; null is not recorded. These are human-entered observations and trigger no marketplace action.

The form sends `{evaluation_id, enrichment_id, human_label, reason_code, notes, outcome, sample_kind}`. Validate enum values and reject unknown keys. Require the currently displayed context; a stale base or enrichment returns 409 and leaves the form available for correction. Lock the authorized opportunity and base evaluation in that order, recheck the current input/profile and displayed enrichment, and persist all review fields together. Identical submissions are no-ops; changed submissions update only the current review.

Reuse `RecordOpportunityReview`, extending it with optional dashboard details while keeping all existing CLI callers valid. A missing details argument preserves notes/outcome; if a CLI call changes label/reason, clear any earlier enrichment reference so it does not imply that the new judgment used those details. A dashboard call supplies its explicit enrichment/null and replaces the allowed details. Include them in identical-review detection.

A disagreement reason is still required when the human label differs from the **original email suggestion**, even if it agrees with the enriched suggestion. Label this clearly in the form. This preserves the Phase 3 question: whether email-only suggestions agree with the owner's informed judgment.

Manual results remain in the new enrichment table; they are never inserted as email evaluations or substituted into `BuildCalibrationReport`. Human judgments may use manually inspected full details, as already allowed by Phase 3. Private UI reviews default to `demo`; marking `real` is an explicit operator declaration of genuine provenance and requires a personal-purpose active profile. Demo mode always forces `demo`. Human outcomes such as hired are not required to calibrate suitability.

### HTTP and presentation contracts

Register the following in `routes/web.php`; JSON routes use the same authenticated session and CSRF middleware as pages. These are application endpoints, not a public API.

| Method and route                                        | Purpose                                                    |
| ------------------------------------------------------- | ---------------------------------------------------------- |
| GET /login; POST /login; POST /logout                   | Private session entry/exit                                 |
| GET /demo; POST /demo-session                           | Synthetic demo entry, available only in demo mode          |
| GET /opportunities                                      | Queue page shell                                           |
| GET /opportunities/{opportunity}                        | Authorized detail page shell                               |
| GET /review/v1/opportunities                            | Filtered, paginated queue JSON                             |
| GET /review/v1/opportunities/{opportunity}              | Details, original result, current enrichment and review    |
| POST /review/v1/opportunities/{opportunity}/evaluations | Explicit current email evaluation using the server profile |
| POST /review/v1/opportunities/{opportunity}/enrichments | Save/re-evaluate manually confirmed details                |
| PUT /review/v1/opportunities/{opportunity}/review       | Record or revise feedback for the displayed context        |

Successful reads/writes return 200 JSON. The web Evaluate action reuses the Phase 3 identity and updates the authorized opportunity's review pointer, including when previously seen inputs return an older existing evaluation. This prevents A → B → A input changes from leaving the queue on B. All GETs perform no evaluation/review/enrichment writes or pointer updates. No request accepts a workspace ID, score, result JSON, engine version, or profile path.

Return explicit resource DTOs, not whole Eloquent serialization. List JSON is `{data: [...], meta: {current_page, per_page, total, last_page}}`. Each row exposes only displayed fields, opportunity/evaluation IDs, basis, current enrichment ID, stale flag and review status. Detail JSON adds the displayed opportunity fields, base result, saved/current input comparison, latest enrichment revision and text, and current review. The original email result and current manual result are both available for comparison; older revisions remain stored without requiring a history-management screen.

Errors use `{error_code, message, errors?}` with fixed safe messages and field errors for validation. Use 401 for unauthenticated JSON, 403 for an unassigned workspace, 404 for absent/foreign objects, 409 for stale context, 422 for invalid input, 429 for throttling, and 503 for unavailable configuration/storage. HTML guests redirect to login; missing/invalid CSRF requests are rejected by the installed Laravel middleware. Handle expired sessions/CSRF failures in the UI without announcing a successful save. Never return exception text or private paths. Reject bodies over the limit with 413.

Use Vue components mounted in Blade page shells, same-origin fetch with JSON accept headers and the Laravel CSRF token, and normal links between pages. Keep unsaved drafts in memory only. Disable the active Save button during a request, preserve input on failure, and show success only after the server confirms persistence. Use local assets and system fonts; remove the unused remote-font configuration. Do not use `v-html`, raw Blade output or linkification for descriptions/notes.

### Synthetic demo and operator setup

Provide `database/seeders/ReviewDemoSeeder.php` with fixed synthetic fixtures: two workspaces and assigned users for isolation tests; at least 26 opportunities in the visible workspace covering all suggestions, UNSCORED, unknown rates/ratings/skills, ties and existing feedback. Use the committed demo profile and existing scorer to create evaluations. Do not seed real names, addresses, job identifiers, tracking values, or alert prose.

A deployment in `mode=demo` uses a **separate database containing only these fixtures**, disables mailbox intake, and serves a CSRF-protected Start demo button. That POST starts a session for the configured seeded user and its one workspace; neither user nor workspace is selectable by the request. Generate an unusable/unpublished random password for this account. Private mode must return 404 for both demo-entry routes, even if a demo user happens to exist.

A shared demo accepts review labels/reasons/outcomes only from their enums, notes only as empty or a chosen fixed example, and enrichment only by an allowlisted **preset key**. Resolve that preset to description/overrides on the server; reject arbitrary text or forged override bodies. The same enrichment action and UI result display are then exercised without publishing visitor-supplied private text. The UI says synthetic data, uses a preset selector instead of the private textarea, and explains that shared changes may be reset. It never accepts `sample_kind=real`.

The seeder refuses to run outside demo mode and refuses an existing database containing non-demo application records. Re-running it must not overwrite unrelated records. Keep any reset procedure confined to a verified disposable demo/test database. Private account provisioning and workspace assignment are documented operator steps, not another user-management feature.

### Implementation slices and named artifacts

Follow the supplied Scrum-XP Action → Task → named tests/Gherkin → audit → evidence workflow. Apply the repository Laravel/testing guidance; use Tailwind 4 conventions for the UI.

1. **Protected read journey.** Add the user-workspace and selected-evaluation-pointer migrations, configuration, session flow, middleware/policy, reusable profile/input loaders, queue/detail resources and queries. Build the two Vue screens with read-only explanations and explicit Evaluate. Add `vue` 3, a Vite-8-compatible `@vitejs/plugin-vue`, and a committed npm lockfile; preserve the existing project rather than scaffolding over it. Keep Node's roadmap baseline, verifying package engines during installation. No new PHP runtime dependency.
2. **Enrichment and feedback.** Add the enrichment table/model, review columns, validated requests, transactional actions, provenance/stale handling and forms. Record the integration decisions in `.github/docs/adr/ADR-007-review-dashboard-and-manual-enrichment.md` (ADR-006 remains reserved by the roadmap). Test old Phase 3 commands and calibration semantics alongside new HTTP paths.
3. **Usable demonstration and acceptance.** Add the synthetic seeder/presets, opt-in demo entry, the browser acceptance test described below, README instructions and `.github/docs/runbooks/phase4-review-dashboard.md`. Verify on the existing target hosting with compiled assets; retain the existing three required checks and complete only this phase's acceptance work.

Concrete paths: `app/Http/Controllers/Auth/SessionController.php`, `DemoSessionController.php`; `app/Http/Controllers/OpportunityReviewController.php` (index/show), `OpportunityEvaluationController.php` (store), `OpportunityEnrichmentController.php` (store), `OpportunityFeedbackController.php` (update); corresponding requests under `app/Http/Requests/Review/` and explicit resources under `app/Http/Resources/Review/`; `app/Http/Middleware/EnsureReviewWorkspace.php`; `app/Policies/OpportunityPolicy.php`; `app/Application/Review/ListReviewOpportunities.php`, `ShowReviewOpportunity.php`, `RefreshReviewEvaluation.php`, `SaveOpportunityEnrichment.php`; `app/Application/Triage/BuildOpportunityTriageInput.php`; `app/Models/OpportunityEnrichment.php`.

UI paths: `resources/views/auth/login.blade.php`, `resources/views/review/{index,show,demo}.blade.php`; `resources/js/review/{QueuePage,OpportunityPage,ScoreExplanation,EnrichmentForm,FeedbackForm}.vue`; `resources/js/review/http.js`; existing `resources/js/app.js`, CSS and Vite config. Keep helpers local and share the scorer rather than implementing scoring in JavaScript.

### Accessibility and interaction acceptance

Use semantic headings, landmarks, labeled native inputs and buttons. Provide a skip link, visible keyboard focus, logical tab order, and text labels independent of badge color. Explain APPLY/MAYBE/SKIP as suggestions. Group override controls with fieldsets; connect help/errors using `aria-describedby`, mark invalid inputs, and focus the error summary or first invalid field after a failed submission. Announce saved state and filter-result counts through a polite status region. Keep focus stable when results refresh.

At 320 CSS pixels and 200% zoom, forms and primary actions remain usable without page-level horizontal scrolling; use a responsive list or contained table. Check readable contrast (4.5:1 ordinary text), 24 CSS pixel minimum targets where applicable, and keyboard operation without a trap. Test loading, empty, validation, stale, offline and expired-session states. Use [WCAG 2.2 guidance](https://www.w3.org/WAI/WCAG22/quickref/) as the implementation reference; passing a smoke test is not a claim of full conformance certification.

### Test plan and behavior traceability

Use real MariaDB queries and existing factories for backend tests. The feature file is mapped to PHPUnit and a small Playwright browser suite; no Gherkin runtime or duplicate JavaScript scorer tests are needed.

| Scenario                                                  | Primary executable case                                                                                                                                                                                                |
| --------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Protect the private review journey                        | `ReviewAuthenticationTest::it_protects_private_pages_and_expires_logged_out_sessions`                                                                                                                                  |
| Isolate every opportunity operation                       | `ReviewWorkspaceIsolationTest::it_denies_foreign_records_on_every_review_route`                                                                                                                                        |
| Rank and filter the saved review queue                    | `ReviewQueueTest::it_ranks_filters_and_paginates_saved_suggestions`                                                                                                                                                    |
| Evaluate an unscored opportunity explicitly               | `ReviewEvaluationTest::it_evaluates_explicitly_and_reuses_unchanged_results`                                                                                                                                           |
| Explain uncertain evidence                                | `ReviewQueueTest::it_exposes_reasons_unknowns_and_manual_review_context`                                                                                                                                               |
| Record an independent human decision                      | `ReviewFeedbackTest::it_saves_feedback_without_changing_machine_evidence`                                                                                                                                              |
| Save against the displayed effective evaluation           | `OpportunityEnrichmentTest::it_saves_against_the_displayed_fallback_evaluation_without_selecting_a_pointer`; `ReviewFeedbackTest::it_saves_feedback_for_the_displayed_fallback_evaluation_without_selecting_a_pointer` |
| Keep identical feedback as a true no-op                   | `ReviewFeedbackTest::it_rejects_stale_contexts_and_updates_only_the_current_review`; `RecordOpportunityReviewTest::it_records_human_feedback_without_changing_the_machine_result`                                      |
| Re-evaluate confirmed details without rewriting the email | `OpportunityEnrichmentTest::it_scores_confirmed_details_and_preserves_email_evidence`                                                                                                                                  |
| Keep prose separate from scoring inputs                   | `OpportunityEnrichmentTest::it_changes_no_score_for_description_only_enrichment`                                                                                                                                       |
| Preserve successive manual contexts                       | `OpportunityEnrichmentTest::it_preserves_revisions_and_reuses_identical_retries`                                                                                                                                       |
| Refuse a stale editing context                            | `OpportunityEnrichmentTest::it_rejects_stale_base_and_manual_contexts`                                                                                                                                                 |
| Preserve email-only calibration semantics                 | `ReviewFeedbackTest::it_keeps_calibration_on_email_predictions_and_human_labels`                                                                                                                                       |
| Reject unsafe or excessive input                          | `ReviewInputSafetyTest::it_rejects_invalid_writes_and_escapes_private_text`                                                                                                                                            |
| Run the synthetic demo without credentials                | `ReviewDemoTest::it_limits_demo_sessions_and_writes_to_synthetic_fixtures`                                                                                                                                             |
| Complete the browser review journey by keyboard           | `tests/Browser/review-dashboard.spec.js::keyboard_review_journey`                                                                                                                                                      |
| Keep an unsuccessful save visibly unsaved                 | `tests/Browser/review-dashboard.spec.js::recoverable_save_failure`                                                                                                                                                     |

Place the named PHP tests in `tests/Feature/`. Cover login throttling, no workspace assignment, unknown request keys, all mutation routes' guest/foreign access, nested foreign evaluation/enrichment IDs, invalid profile configuration, enum/size/decimal validation, omitted versus null/false overrides, description-only rescoring, revision idempotency, email-input A → B → A selection, stale 409 rollback, current-review filtering, and Phase 3 CLI compatibility. Verify HTTP and mailbox boundaries with existing fakes/stray-request prevention. Tests must assert persisted state and safe outputs, not just status codes.

Add `@playwright/test` as a development dependency and a minimal `playwright.config.js`. Use Chromium only, one worker, the real Laravel server with built Vite assets, and the isolated MariaDB test database. Run private and demo browser passes sequentially, with the server mode fixed at startup and the disposable test database reseeded between passes. Create named private/demo fixtures in test setup; do not create a public test-setup route or runtime mode-switch endpoint. The browser suite exercises the actual frontend and endpoints, blocks/records requests outside its local origin, checks escaped text in the private UI, and verifies a forged cross-site write is rejected with no database mutation. It includes the desktop and narrow-viewport keyboard journey. See [Playwright server setup](https://playwright.dev/docs/test-webserver) and [network interception](https://playwright.dev/docs/network).

### CI, runbook and definition of done

Extend the existing `Tests / MariaDB 11.4` job to install the pinned Node/npm dependency set, run `npm ci`, `npm run build`, and `npm run test:e2e` after the PHP suite. Before browser setup, verify the existing MariaDB safety contract; recreate/reseed only that disposable `*_test` database. Fail the existing required check if browser assertions fail. Use SHA-pinned setup actions consistent with the workflow; production runs only the compiled assets, not Node or Playwright.

Keep PHP coverage gates at 80% overall, 90% parser/domain and 90% triage domain. Run Composer validation/audit, PHPStan, Pint, the complete MariaDB suite, build, browser tests, npm dependency audit, secret scanning and `git diff --check`; handle actual relevant dependency findings without weakening existing gates. Add no new coverage percentage or generic infrastructure acceptance project.

The runbook covers private account/workspace assignment, server-only profile configuration, synthetic demo setup, safe demo reset, migrations and asset deployment, the manual keyboard/mobile/error sweep, and a short target-host smoke: login/demo entry → queue → detail → confirmed-field re-evaluation → saved feedback → reload → logout. Verify no marketplace/mailbox request occurs. Record commit and results using synthetic screenshots and counts only; keep private descriptions, notes and profiles out of logs and published evidence.

**Done:** the specified journeys work, the mapped tests and existing checks pass on the candidate commit, the manual accessibility sweep and target-host synthetic browser smoke pass, and ADR/README/this Phase 4 section reflect the implementation. Preserve existing evaluation history and source rows. Application rollback removes access to the new routes/assets while preserving additive data; it does not run destructive down migrations against personal data.

Record the Phase 3 product decision independently. Under this portfolio-demo decision, state that the first real calibration completed and failed its targets; do not describe calibration as pending or successful. Phase 4 delivers the functional MVP interface, and Phase 5 remains the publication milestone. This entry claims implementation, protected CI, isolated synthetic deployment, target-host verification, and Phase 4 completion.

### Source anchors

- [Reviewed calibration status and existing contracts](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/ff499f3f30b71ba4fef647a426a0b61cfcf4a6dd/.github/docs/project_sheet.md#phase-3-explainable-triage-and-feasibility-gate) and [Phase 4 roadmap](https://github.com/KontentWave/freelance-opportunity-triage-platform/blob/ff499f3f30b71ba4fef647a426a0b61cfcf4a6dd/.github/docs/PROJECT_ROADMAP.md#phase-4--accessible-review-dashboard-and-manual-enrichment).
- Existing repository models, routes, composer/package manifests, scorer and review actions at the same pinned commit; the supplied Scrum-XP guides.
- [Vue build integration](https://vuejs.org/guide/quick-start.html), [Vite requirements](https://vite.dev/guide/) and [Vue text-rendering security](https://vuejs.org/guide/best-practices/security.html). Package versions and API details must match the lockfiles selected during implementation.

---

## Phase 5: Portfolio Release and Compliant Extensibility

**Document role:** Phase 5 scope and Slice 1-2 contracts; earlier phases remain historical as-built records.
**Status:** Slices 1 and 2 completed and merged in [PR #15](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/15) and [PR #29](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/29), respectively; Slice 3 and publication remain pending.
**Accepted baseline:** `d1818df5ec2e5211319393ab1726622e53b02028` (merged PR #14). Its [CI run](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/35769060483) passed 176 PHP tests / 1,361 assertions, two Chromium tests, and coverage of 90.93% overall, 93.67% parser/domain and 96.90% triage domain. These are baseline results, not Phase 5 results.
**Associated feature:** `release_portfolio_demo.feature` was referenced in the preserved draft but is not yet present in the repository.

### Action

Release version `v1.0.0` as a reproducible, sanitized portfolio demonstration, with understandable operating evidence and a documented boundary for future authorized sources.

The release demonstrates engineering quality. The first real calibration remains `READY`, 30 reviewed imports, 3.33% machine skip rate, 9.09% false-negative rate and `targets_met=false`. Do not claim a feasibility GO, measured time savings, comprehensive marketplace coverage, or validated commercial usefulness.

### Scope and stopping boundary

Keep PHP 8.4, Laravel 13, MariaDB 11.4, Vue, and the repository's Node 22.23 baseline. Reuse Phase 4 authentication, isolation, demo fixtures, browser tests and the three required CI checks.

Phase 5 adds:

- A clean-checkout demo route, a concise portfolio/architecture guide, synthetic screenshots, a threat model, release/rollback instructions and dependency-update configuration.
- One read-only, workspace-scoped CLI aggregate report using existing tables.
- An SBOM, a candidate archive with compiled assets, checksum validation and manually initiated release publication.
- Documentation of existing provider boundaries and the prerequisites for any future source.

No new scoring rules, recalibration cohort, mailbox soak, marketplace adapter, API/OAuth access, OCR, AI, queues, hosted monitoring stack, billing, tenant administration, or Tester Skill. No new application tables or runtime PHP dependencies. Dependency installation, audits and GitHub release operations may use their normal services; application/demo acceptance must make no mailbox or marketplace connection.

Use three implementation slices in order. This section specifies Slices 1 and 2; the Slice 3 contract remains to be recorded. Give Copilot the Phase 5 section and applicable behavior contract, not a request to redesign the roadmap. Repository conventions take precedence over generic playbook examples.

### Task — Slice 1: Make the accepted demo understandable and repeatable (complete)

The accepted Slice 1 scope was delivered in [PR #15](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/15):

1. `README.md` now leads with the synthetic demo, its limits and the supported stack; the former Phase 1-only scope is labeled historical.
2. `.env.demo.example` supplies synthetic local settings with a blank application key, demo user `41001`, mailbox intake disabled and a separate `_demo` database. The real `.env.demo` remains ignored.
3. The opt-in `mariadb_demo` Compose profile uses MariaDB 11.4, a separate disposable volume and loopback port 3308, without changing the `_test` database.
4. The documented clean-checkout walkthrough installs locked dependencies, generates a demo key, migrates and seeds the existing `ReviewDemoSeeder`, builds assets and enters `/demo`. Playwright continues to reset only `_test`, never `_demo`.
5. `.github/docs/portfolio.md` records the architecture, data boundaries, actual ADR links, threat model, future-source requirements, limits and evidence without inventing missing ADRs.
6. Three synthetic screenshots show the ranked queue, confirmed-detail explanation and saved feedback, with descriptive alt text and no browser/account chrome. The local demo requires no marketplace credentials; no unverified hosted demo URL is claimed.
7. `CHANGELOG.md` has an Unreleased entry. Existing license metadata and third-party notices were left alone; the Scrum-XP guides were not made release assets.
8. `.github/dependabot.yml` schedules weekly Composer, npm and GitHub Actions updates with three open PRs per ecosystem and no auto-merge. Subsequent dependency PRs were reviewed and merged separately; they were not a prerequisite for completing Slice 1.

#### Threat model and public evidence

The portfolio guide contains a compact threat table with asset/trust boundary, threat, implemented control, verification and residual limitation. It covers email input, stored text, session/CSRF protection, workspace isolation, private/demo database separation, secrets/history/artifacts and dependency/release integrity.

The existing full-history secret scan remained in place. A bounded human review of the committed fixtures, documentation, screenshots and proposed assets found no private content in the material examined; recognizable synthetic email addresses and IDs were retained for tests. Neither that review nor a passing scan proves the whole repository or future release archive free of private data. Review the exact candidate assets again before publication; if actual private content is found, stop and obtain an explicit remediation decision before rewriting history.

#### Provider contract — documentation only

The guide documents the existing `OpportunityEmailParser::parse(string $rawEmail): ParsedOpportunity` contract, `ParsedOpportunity`, its binding in `AppServiceProvider` and the `ImportOpportunityEmail` persistence/idempotency boundary. These are email-specific; a general API source contract is not implemented.

For a future source, the guide requires documented authorization for the intended access method; a stable provider plus external ID; explicit unknown values; provenance; safe canonical links; workspace-owned persistence; deduplication; replaceable transport; sanitized fixtures; and failure/no-network contract tests. It identifies the email assumptions needing a separate ADR/specification before an API adapter can be built. Slice 1 added no registry, placeholder adapter, credentials or network calls.

#### Completion evidence and limits

The [Slice 1 verification record](../../README.md#slice-1-verification-2026-09-23) distinguishes an initial overlay smoke from the later clean checkout of committed candidate `1d02dae366f4e855ac9fde07b11a17f6869a16f1`. The latter used PHP 8.4.12, Composer 2.9.5, Node 22.23.0 and MariaDB 11.4, with a separate demo volume and external port override to avoid the existing service. Locked installs, key generation, migration, seeding and production build passed. Browser checks covered demo entry, 25/1 pagination, foreign-workspace 404, preset enrichment and saved feedback after reload, logout and same-origin resources. The isolated database held 2 workspaces, 2 users and 28 synthetic opportunities, with no mailbox or email-import rows. The bounded publication-material review found no private material in the examined files.

PR #15 merged as [`77975ec`](https://github.com/KontentWave/freelance-opportunity-triage-platform/commit/77975ec7d16a85121e64c800acccdd183536131d); its post-merge [CI run 35890766817](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/35890766817) passed Quality, Tests / MariaDB 11.4 and Secret scan. The local browser observation was not a server-side network capture. Final candidate privacy review, target-host release acceptance, rollback rehearsal, SBOM, archive and publication belong to later slices; no `v1.0.0` release is claimed.

### Task — Slice 2: Add lightweight operational reporting (complete)

Implement:

- `app/Application/Operations/BuildOperationalSummary.php`
- `app/Console/Commands/OpportunitySummaryCommand.php`
- `tests/Feature/OperationalSummaryTest.php`

Command: `php artisan opportunity:summary --workspace=<workspace-ulid> --json`.

This is a trusted operator CLI, with no HTTP route, scheduler, external collector or public dashboard. Require an explicit existing workspace and use the configured review profile through `LocalScoringProfileLoader`. Never fall back from an invalid private profile to the demo profile. Demo mode uses the existing bundled profile.

Read existing tables only. Capture one UTC instant `T`; the reporting window is inclusive `[T - 24 hours, T]`. Scope every query to the selected workspace, including historical records across its mailbox keys. Do not expose identifiers, names, email metadata, titles, descriptions, notes, profile paths, connection settings or query text.

The JSON success contract is exactly:

```json
{
    "schema_version": 1,
    "generated_at": "<UTC ISO-8601 timestamp>",
    "window_hours": 24,
    "mailbox": {
        "completed_runs": 0,
        "processed_count": 0,
        "duplicate_count": 0,
        "quarantined_count": 0,
        "last_successful_poll_age_seconds": null,
        "unfinished_count": 0,
        "oldest_unfinished_age_seconds": null
    },
    "parser": {
        "quarantined_imports": 0,
        "error_counts": {}
    },
    "triage": { "APPLY": 0, "MAYBE": 0, "SKIP": 0, "UNSCORED": 0 }
}
```

| Field                                               | Required meaning                                                                                                                                                                                   |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `mailbox.completed_runs`                            | Count finished `succeeded`, `partial` or `failed` runs with `finished_at` in the window; exclude unfinished and overlap-skipped runs.                                                              |
| Three mailbox counters                              | Sum the respective persisted counters on those runs. They are recorded processing outcomes, not distinct jobs or a complete audit of interrupted runs.                                             |
| `last_successful_poll_age_seconds`                  | Non-negative integer age of the latest `succeeded` run ending at/before T, even if older than the window; null if none.                                                                            |
| `unfinished_count`, `oldest_unfinished_age_seconds` | Current `pending`/`retry_wait` rows first seen at/before T and the age of the oldest; no rows means 0/null.                                                                                        |
| `parser.quarantined_imports`                        | Quarantined `email_imports` with `imported_at` in the window. Count stored import records, not redelivery attempts.                                                                                |
| `parser.error_counts`                               | Counts by the existing `EmailParseErrorCode` values; unknown/null codes aggregate as `unknown_error`. Emit only nonzero entries with stable key ordering.                                          |
| `triage`                                            | Count each workspace opportunity once using the same valid-pointer/newest-eligible base and latest-enrichment result as the Phase 4 queue for the active profile/engine. No result means UNSCORED. |

These ages are polling/backlog proxies for ingestion delay; they are not measured email-delivery latency. Triage counts describe saved suggestions and may include stale snapshots; they do not measure accuracy or update scores. Use SQL aggregates/subqueries and reuse the queue selection semantics without paginating, loading message bodies, or using the write-side locking resolver for a read. Do not change the existing `opportunity:mailbox-health` command or its exit-code semantics.

An empty workspace is a successful all-zero/null report. Success exits 0; text mode presents the same information. Missing, malformed or absent workspace exits 1 with `summary.invalid_workspace`; unusable profile exits 1 with `summary.profile_unavailable`; unexpected operational failure exits 1 with `summary.unavailable`. JSON errors contain only `schema_version` and `error_code`. Output fixed safe messages, never exception text. No writes, scoring, imports or outbound requests occur.

Use deterministic MariaDB tests for the exact window boundaries, an old successful poll, pending/retry age, completed-run counters, parser-code sanitization, empty state, two-workspace isolation and queue-aligned distributions (selected older result, fallback result, latest enrichment and UNSCORED). Prove persisted records remain unchanged.

#### Completion evidence and limits

The read-only `opportunity:summary` command and `BuildOperationalSummary` action were merged in [PR #29](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/29) as commit [`e86715d`](https://github.com/KontentWave/freelance-opportunity-triage-platform/commit/e86715dbc7b5605e04bef01e7d89557c0a0b160e) from candidate `ce20708`. It requires an existing explicit workspace ULID, loads the configured review profile without private-to-demo fallback, and emits only aggregate counts and fixed safe error codes. The report captures one UTC instant for the inclusive 24-hour window, uses bounded SQL aggregates and the existing queue's read-side evaluation/enrichment selection, and makes no scoring or persistence call. It adds no route, scheduler, table, collector or dependency; the mailbox-health command is unchanged.

Local MariaDB validation passed with 181 PHP tests and 1,399 assertions. The five new `OperationalSummaryTest` cases cover empty output, inclusive and future window boundaries, old successful poll and pending/retry ages, multi-key/workspace isolation, recognized and unknown parser-code aggregation, all four queue suggestion buckets including pointer fallback and latest enrichment, fixed error output, and unchanged persisted records. PCOV measured 91.18% overall (2,441/2,677 statements), 93.67% parser/domain (222/237), and 96.90% triage domain (344/355), meeting the existing gates. PHPStan, Pint, strict Composer validation, locked Composer audit, and `git diff --check` passed locally; the audit found no known advisories. The PR's required Quality, Tests / MariaDB 11.4, and Secret scan checks passed before merge. These are implementation checks, not a target-host acceptance, release candidate, or `v1.0.0` publication claim.

The reported ages are polling/backlog proxies rather than measured email-delivery latency. Saved triage suggestions can be stale and are not an accuracy measure. The trusted operator CLI is not an HTTP endpoint or a hosted monitoring service; Slice 3's release and publication requirements remain pending.

**Scope of the following two sections:** “Test plan and behavior traceability” and “Perf, security and accessibility notes” apply across all three Phase 5 slices. Implement and verify each deliverable and test in its relevant slice; items assigned to later slices remain planned until implemented and verified. Shared security, privacy and accessibility constraints apply throughout. Phase 5 completion requires all applicable requirements to be satisfied.

### Test plan and behavior traceability

The feature is an acceptance specification mapped to existing PHPUnit/Playwright, new PHPUnit/Node tests and explicit operator checks. Do not add a Gherkin runtime or claim that document inspection executes application scenarios.

| Feature scenario / requirement             | Verification                                                                                                                                                                                                 |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Launch the documented synthetic demo       | Clean-checkout operator walkthrough; existing `ReviewDemoTest` and `tests/Browser/review-dashboard.spec.js`.                                                                                                 |
| Report scoped operational evidence         | `OperationalSummaryTest::it_reports_workspace_scoped_counts_and_ages`.                                                                                                                                       |
| Match the displayed queue distribution     | `OperationalSummaryTest::it_counts_each_current_queue_suggestion_once`.                                                                                                                                      |
| Handle empty and unavailable state safely  | `OperationalSummaryTest::it_reports_empty_state_without_writes`; `it_rejects_invalid_context_without_disclosure`.                                                                                            |
| Explain the portfolio boundary honestly    | Operator review of README, portfolio guide, synthetic screenshots and actual adapter references.                                                                                                             |
| Maintain dependencies through reviewed PRs | Review Dependabot configuration and unchanged PR gates; no waiting for a scheduled update.                                                                                                                   |
| Prepare a traceable candidate              | `tests/Release/release-candidate.test.mjs`: `packages only approved source and compiled assets`; `validates PHP and JavaScript SBOM evidence`; actual CI packaging step.                                     |
| Refuse incomplete release evidence         | Same Node suite: `rejects ineligible runs and mismatched candidate evidence before publication`, table-driven for each release gate. Mock only GitHub/CLI side effects; use real temporary files and hashes. |
| Publish the archive tested on the host     | Node suite: `promotes the verified candidate without rebuilding or overwriting a tag`; one real operator-authorized release run.                                                                             |
| Recover without losing review data         | Synthetic target-host rollback rehearsal, recorded in the release runbook.                                                                                                                                   |

New tests must detect the corresponding failures: foreign-workspace counts, double-counted evaluations/revisions, incorrect time windows, fabricated zero ages, raw error-code leakage, environment-file inclusion, missing ecosystem inventory, altered archive, wrong run/SHA/attempt, non-main or failing CI, absent smoke, and existing tags. Use controlled time and synthetic fixtures. Keep the existing Phase 4 fallback-evaluation and timestamp-idempotency regressions.

Run focused tests during implementation, then existing CI gates once per candidate. Keep actual counts and coverage in evidence only after execution; the baseline totals above are not targets to hard-code. Documentation-only changes need review, not placeholder tests. An unavailable required tool or host check is reported as pending, never passed.

### Perf, security and accessibility notes

Reporting must use bounded query structure/SQL aggregates over indexed workspace/time fields; avoid per-row queries and full private payload hydration. No new latency SLO, schema/index migration or monitoring service is required without evidence of a concrete problem.

Public evidence uses synthetic data only; the aggregate CLI is local to the trusted operator and cannot be called through demo HTTP routes. Dependency scanning checks known advisories and does not establish safety against every vulnerability. Build inventory scope and manual smoke attestation must be described honestly.

Keep the existing keyboard, focus, error-state and narrow-screen behavior. Provide useful alt text and readable captions for portfolio screenshots. Publication introduces no new product UI.

### Implementation references

Verify exact flags and pinned tool/action versions during implementation; do not invent them:

- [GitHub workflow artifacts](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/download-workflow-artifacts)
- [GitHub workflow syntax and permissions](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- [GitHub CLI release creation](https://cli.github.com/manual/gh_release_create)
- [Dependabot configuration](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
- [Syft SBOM generation](https://oss.anchore.com/docs/guides/sbom/getting-started/) and [Composer metadata support](https://oss.anchore.com/docs/capabilities/php/)
