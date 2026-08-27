# Freelance Opportunity Triage Platform

This repository currently implements Phase 1: offline normalization of supported Upwork hourly job-alert emails into workspace-owned opportunity records.

The Phase 1 slice is intentionally local-only. It parses a raw `.eml` file, reads the plain-text MIME part, normalizes the observed hourly alert fields, and persists safe workspace-scoped records without contacting Gmail, IMAP, Upwork, or any other external service.

## Current Phase 1 Scope

Included:

- local `.eml` import through `php artisan opportunity:import-email`
- Upwork hourly alert parsing from the plain-text MIME part
- workspace-scoped opportunity persistence and idempotent import tracking
- quarantine records with stable parser error codes
- PHPUnit coverage for parser, import action, command behavior, and schema constraints

Not included:

- Gmail, IMAP, OAuth, mailbox polling, queues, or cron
- fixed-price alerts
- dashboards, scoring, AI triage, or proposal workflows
- HTTP enrichment or any remote fetch against Upwork

## Local Requirements

- PHP 8.4
- Composer 2
- the PHP extensions required by the installed dependencies, including `mbstring` and `iconv`

The automated test suite uses the repository's PHPUnit test database configuration. The production-oriented schema is designed around explicit workspace ownership and workspace-scoped uniqueness.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

If you want a local database for manual command runs, configure `.env` and run:

```bash
php artisan migrate
```

## Local Import Command

The command requires both:

- a readable local `.eml` path
- an existing workspace ULID passed through `--workspace`

Usage:

```bash
php artisan opportunity:import-email tests/Fixtures/Emails/upwork/hourly-client-success.eml --workspace=<workspace-ulid>
```

Example output on success:

```text
status: imported
opportunity_id: 01K3MEXAMPLEULID1234567890
external_job_id: 200000000000000000001
```

Example output on quarantined input:

```text
status: quarantined
error_code: unsupported_sender
```

Exit codes:

- `0` for `imported`, `updated`, and `duplicate`
- `1` for `quarantined` or invalid command input

## Supported Template Limits

Phase 1 currently supports only the observed Upwork hourly alert template represented by the fixtures in `tests/Fixtures/Emails/upwork`.

Current parser expectations:

- `From` must be `donotreply@upwork.com`
- `Subject` must start with `New job alert:`
- a non-empty `text/plain` MIME part must exist
- the body must contain an HTTPS Upwork job URL on `www.upwork.com` with a `/jobs/~<digits>` path
- hourly terms must match `Hourly: $<min> - $<max>`

Current normalization behavior:

- raw email size is capped at `1_048_576` bytes before MIME parsing
- canonical URLs are stripped down to `https://www.upwork.com/jobs/~<id>`
- HTML entities and repeated whitespace are normalized
- `$0.00 - $0.00` is treated as an unknown hourly range
- visible skills are deduplicated case-insensitively in source order
- `+N more` is tracked separately as `hidden_skill_count`

## Fixtures

Sanitized fixtures live in `tests/Fixtures/Emails/upwork`:

- `hourly-client-success.eml`
- `hourly-operations-coordinator.eml`
- `hourly-unknown-rate.eml`

These fixtures are synthetic and safe to commit. They preserve MIME structure and intentionally include fake identifiers and fake tracking-like values so the tests can prove the import flow never persists or prints them.

## Phase 1 Privacy and Safety Constraints

Phase 1 treats every email as untrusted input.

The implementation does not:

- persist raw email bodies
- persist recipient addresses
- persist query parameters, fragments, or tracking tokens from job links
- render or execute HTML MIME content
- make outbound network requests during parsing or import

Typed parse failures are recorded only as safe quarantine metadata: workspace, optional safe message ID, content hash, status, and stable error code.

## Validation

Focused checks during development should use the narrowest relevant PHPUnit files first.

The baseline repository checks are:

```bash
php artisan test --compact
vendor/bin/phpstan analyse
vendor/bin/pint --dirty --format agent
```

## Reference Docs

- Phase 1 as-built specification: `.github/docs/project_sheet.md`
- Phase 1 behavior scenarios: `.github/docs/features/normalize_job_alert.feature`
