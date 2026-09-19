# Freelance Opportunity Triage Platform

This repository implements offline normalization, scheduled mailbox intake, deterministic explainable triage, and an accessible review dashboard for supported Upwork hourly job-alert emails. The first 30-item private calibration completed but did not meet its provisional targets; Phase 4 proceeds only as an explicitly authorized portfolio demonstration.

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
- MariaDB 11.4
- Node.js 22.23 or later
- the PHP extensions required by the installed dependencies, including `mbstring` and `iconv`

The automated test suite is MariaDB-only. SQLite is not a supported substitute for Phase 1 database validation.

The production-oriented schema is designed around explicit workspace ownership and workspace-scoped uniqueness.

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

For the test suite, copy the committed test template and point it at a dedicated MariaDB database whose name ends in `_test`:

```bash
cp .env.testing.example .env.testing
```

To use the repository-managed MariaDB 11.4 test service:

```bash
docker compose up -d mariadb_test
```

Minimum MariaDB test settings:

- `DB_CONNECTION=mariadb`
- `DB_DATABASE=<your_database_name_ending_in__test>`
- valid non-production `DB_USERNAME` and `DB_PASSWORD`

The committed `compose.yaml` service uses these defaults:

- `DB_HOST=127.0.0.1`
- `DB_PORT=3307`
- `DB_DATABASE=freelance_opportunity_triage_platform_test`
- `DB_USERNAME=app`
- `DB_PASSWORD=app`

The local test service intentionally uses host port `3307` so it does not collide with any machine-level MySQL or MariaDB instance already bound to `3306`.

You can stop and remove the local test database with:

```bash
docker compose down -v
```

The test bootstrap refuses to run destructive test database operations unless all of the following are true:

- `APP_ENV=testing`
- the configured driver is MySQL or MariaDB
- the configured database name ends with `_test`

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

- `From` must exactly match `donotreply@upwork.com` or `upwork@t.upwork.com`
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

## Synthetic Triage Walkthrough

The committed profile at `resources/triage/profiles/demo-v1.json` is synthetic demonstration data. It does not represent personal preferences and cannot establish product feasibility.

```bash
php artisan opportunity:triage <opportunity-ulid> \
	--workspace=<workspace-ulid> \
	--profile=resources/triage/profiles/demo-v1.json \
	--json

php artisan opportunity:review <evaluation-ulid> SKIP \
	--workspace=<workspace-ulid> \
	--reason=fit \
	--sample-kind=demo \
	--json
```

Create a local JSON cohort containing 1–100 distinct evaluation ULIDs:

```json
["01K3MEXAMPLEEVALUATION00001", "01K3MEXAMPLEEVALUATION00002"]
```

Then build an aggregate-only report:

```bash
php artisan opportunity:triage-report \
	--workspace=<workspace-ulid> \
	--evaluations=storage/app/private/triage/demo-cohort.json \
	--json
```

The report never auto-selects evaluations. Its scope is selected, supported imports only; the skip rate is a suggested reduction in listing opens, not marketplace-wide coverage or measured time savings.

## Private Personal Calibration

Keep personal profiles and cohort files under the ignored `storage/app/private/triage/` directory. Do not commit genuine opportunity identifiers, labels, or personal thresholds.

Use a profile with `purpose` set to `personal`, evaluate a predetermined consecutive cohort of at least 30 genuine supported imports, and record each judgment with `--sample-kind=real`. A changed machine/human label requires one of `fit`, `availability`, `economics`, `client_risk`, `missing_information`, or `other` as `--reason`.

`DEMO_ONLY` and `INSUFFICIENT_DATA` reports never pass calibration. The first complete `READY` report produced `targets_met=false`; synthetic demo activity cannot change that historical result.

## Review Dashboard

Install and compile the frontend with the locked dependencies:

```bash
npm ci
npm run build
```

Private mode is the default. Configure an operator-controlled profile path, assign a provisioned user to exactly one workspace, and serve only compiled assets:

```dotenv
OPPORTUNITY_REVIEW_MODE=private
OPPORTUNITY_REVIEW_PROFILE_PATH=/absolute/server/path/to/personal-profile.json
OPPORTUNITY_REVIEW_DEMO_USER_ID=
```

The private login is `/login`. Every review page and mutation derives workspace ownership from the authenticated user; no request selects a workspace or profile.

For a shared synthetic demonstration, use a separate disposable MariaDB database whose name ends in `_demo`, disable mailbox intake, migrate it, and run the guarded seeder:

```dotenv
OPPORTUNITY_REVIEW_MODE=demo
OPPORTUNITY_REVIEW_DEMO_USER_ID=41001
OPPORTUNITY_MAILBOX_ENABLED=false
DB_DATABASE=freelance_opportunity_triage_platform_demo
```

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=ReviewDemoSeeder --force
```

The seeder refuses private mode, mailbox-enabled operation, unsafe database names, and databases containing unrelated application records. `/demo` provides the CSRF-protected credential-free entry. Demo enrichment accepts fixed preset keys only, feedback accepts enum values and an empty or fixed note, and all demo reviews use `sample_kind=demo`.

See `.github/docs/runbooks/phase4-review-dashboard.md` before provisioning, resetting, deploying, or verifying either mode.

## Validation

Focused checks during development should use the narrowest relevant PHPUnit files first.

The baseline repository checks are:

```bash
composer validate --strict
php artisan test tests/Feature/MariaDbTestingEnvironmentTest.php --compact
php artisan test --compact
vendor/bin/phpstan analyse
vendor/bin/pint --dirty --format agent
composer audit --locked
npm ci
npm run build
npm audit
npx playwright install chromium
npm run test:e2e
git diff --check
```

If your local MariaDB credentials are not configured yet, `php artisan test --compact` will fail fast by design instead of falling back to SQLite.

## GitHub Actions

The repository CI workflow lives at `.github/workflows/ci.yml` and defines these jobs:

- `Quality`
- `Tests / MariaDB 11.4`
- `Secret scan`

The test job provisions an isolated MariaDB 11.4 service database, runs the full PHPUnit suite with coverage enabled, builds production assets, and runs the one-worker Chromium acceptance suite. It enforces these thresholds:

- at least 80% overall coverage
- at least 90% coverage for Phase 1 parser/domain code
- at least 90% coverage for `app/Domain/Triage/`

Branch protection is still a manual GitHub setting. After the first successful workflow run on GitHub, enable these exact check names as required status checks on `main`:

- `Quality`
- `Tests / MariaDB 11.4`
- `Secret scan`

Recommended branch protection for this solo project:

- protect `main`
- require a pull request before merging
- require branches to be up to date before merging
- require the checks `Quality`, `Tests / MariaDB 11.4`, and `Secret scan`
- disallow force pushes
- disallow branch deletion
- do not require an approving reviewer count

## Reference Docs

- Current phase as-built specification: `.github/docs/project_sheet.md`
- Phase 1 behavior scenarios: `.github/docs/features/normalize_job_alert.feature`
- Product roadmap: `.github/docs/PROJECT_ROADMAP.md`
