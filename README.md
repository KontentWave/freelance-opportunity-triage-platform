# Freelance Opportunity Triage Platform

This repository starts with the Phase 1 email-normalization slice for a Laravel 13 application. The current goal is to import a supported raw Upwork job-alert `.eml` message, parse its plain-text MIME part offline, and normalize it into a workspace-owned opportunity record without making any network request.

## Current Status

- Laravel 13 scaffold installed.
- PHP baseline pinned to 8.4.12 for local dependency resolution.
- `zbateson/mail-mime-parser` locked at 4.0.3.
- Laravel Boost, Pint, PHPUnit, and Larastan installed.
- Phase 1 application code has not been implemented yet.

## Local Requirements

- PHP 8.4 with `mbstring`, `iconv`, and `pdo_mysql` extensions.
- Composer 2.
- MariaDB 11.4 for the Phase 1 database contract target.

## Project Scope

Phase 1 is intentionally offline-only. It does not include Gmail, IMAP, OAuth, polling, background workers, Upwork scraping, scoring, or a dashboard.

The source-of-truth specification for this slice is the project sheet in `.github/docs/project_sheet.md` and the behavior scenarios in `.github/docs/features/normalize_job_alert.feature`.

## Setup

Install dependencies and prepare the local environment:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

The test suite is currently configured to use an in-memory SQLite database through `phpunit.xml` and `.env.testing`.

## Verification

Run the current baseline checks with:

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

## Next Implementation Step

Build the parser boundary first:

1. Add sanitized `.eml` fixtures under `tests/Fixtures/Emails/upwork`.
2. Add the Phase 1 parser contract, DTO, enums, and parse exception.
3. Implement and unit-test `UpworkJobAlertParser` before persistence or CLI wiring.
