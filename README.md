# Freelance Opportunity Triage Platform

The credential-free `/demo` is a synthetic portfolio walkthrough of a ranked review queue, explainable saved suggestions, confirmed details and human feedback. It uses a dedicated disposable MariaDB database; it does not access a mailbox or marketplace. This is not a validated time-saving tool or a marketplace-wide feed. The first real calibration is `READY` with 30 reviews, a 3.33% machine skip rate, a 9.09% false-negative rate and `targets_met=false`.

The application also supports offline normalization of supported Upwork hourly email alerts, authorized scheduled IMAP intake, deterministic triage and a private authenticated review dashboard. It runs on PHP 8.4, Laravel 13, MariaDB 11.4, Vue and Node 22.23 for building frontend assets. No general marketplace API connector exists.

## Synthetic Demo From a Clean Checkout

Use a disposable local machine. The test database on port 3307 ends in `_test` and is reset by browser tests; the demo database on loopback port 3308 ends in `_demo` and has its own volume. Never point either environment at private data. The example credentials are local only, not deployment credentials.

```bash
composer install --no-interaction --prefer-dist
npm ci
cp .env.demo.example .env.demo
docker compose --profile demo up -d --wait mariadb_demo
APP_ENV=demo php artisan key:generate --env=demo
APP_ENV=demo php artisan migrate --env=demo --force
APP_ENV=demo php artisan db:seed --env=demo --class=ReviewDemoSeeder --force
npm run build
APP_ENV=demo php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/demo` in a browser and start the demo. The seeded dataset has two users, two workspaces and 28 fictional opportunities: the assigned workspace has 26, paginated 25 then 1. The seeded profile and preset controls need no mailbox or marketplace credentials. Saved preset enrichment and feedback survive reload; logout removes access to private review routes.

Do not run Playwright against the demo database: its setup recreates only the isolated `_test` database. Keep `.env.demo` private; commit only `.env.demo.example`. For review-specific behavior see the [review runbook](.github/docs/runbooks/phase4-review-dashboard.md) and [portfolio guide](.github/docs/portfolio.md). Candidate acceptance, publication and rollback belong to the later release-workflow slice.

### Slice 1 verification (2026-09-23)

The initial walkthrough was exercised in a fresh local clone of the accepted `main` baseline with only the then-pending Slice 1 files overlaid, with newly installed locked Composer/npm dependencies and no inherited application database or built assets. That was **not** a clean checkout of a committed Slice 1 candidate. PHP 8.4.12, Composer 2.9.5 and MariaDB 11.4 were used. Local Node 22.18.0 was below the documented 22.23 baseline; `npm ci` and `npm run build` nevertheless completed. The later committed-checkout check is recorded below.

An existing, healthy demo service from the working checkout occupied port 3308. It was not reset or reused. The acceptance clone used an isolated Compose project and disposable volume, with only its temporary copies of the Compose port and `.env.demo` changed to 3309; the server used port 8001. `docker compose up -d --wait`, key generation, migration, `ReviewDemoSeeder` and the production build passed. Read-only database counts were 2 workspaces, 2 users, 28 opportunities (26 assigned to the entered user), and zero email imports, mailbox messages and mailbox runs. Browser checks covered credential-free `/demo` entry, a 25/1 page boundary, a foreign opportunity returning 404, preset enrichment and feedback saved after reload, logout and protected-route redirect, and no outside-origin page resources. This manual browser check does not replace the existing automated browser suite or prove that no server-side outbound request occurred.

After the documentation split, `php artisan test tests/Feature/ReviewDemoTest.php --compact` passed with 5 tests and 29 assertions; `docker compose --profile demo config --quiet`, `composer validate --strict`, the Slice 1 `git diff --check`, and relative documentation-link checks passed. These are local checks, not a Phase 5 protected-CI result.

The committed Slice 1 candidate `1d02dae366f4e855ac9fde07b11a17f6869a16f1` was then cloned into a clean checkout and exercised with PHP 8.4.12, Composer 2.9.5, Node 22.23.0 and MariaDB 11.4. Locked Composer and npm installs, key generation, migration, `ReviewDemoSeeder` and the Vite production build passed; the Git checkout stayed clean. Because the working checkout already used ports 3308 and 8000, the acceptance clone used an external Compose override (outside the checkout) to publish its dedicated demo volume on loopback port 3309 and changed only its ignored `.env.demo` port; the server ran on port 8001. Read-only counts were 2 workspaces, 2 users, 28 opportunities (26 assigned), and zero email imports, mailbox messages and mailbox runs. In the browser, credential-free entry showed 25 records on page one and one on page two; a foreign-workspace detail returned 404. A fixed enrichment changed the displayed score from 60 to 90; APPLY feedback with a fixed note survived reload, while the original suggestion remained visible. Logout redirected protected access to login. Observed browser resources were all same-origin. Mailbox intake was disabled and the isolated database had no mailbox rows, but this browser observation alone is not a server-side network capture. This local check does not substitute for protected CI or later host acceptance.

Manual publication-material review covered all five committed email `.eml` fixtures, the triage golden cases, the demo seed data, the three screenshot pixels and PNG chunk metadata, this README, the portfolio guide, the Phase 5 specification and feature, the existing ADR links, the demo environment example, Compose configuration, Dependabot configuration and changelog. The fixtures use recognizable synthetic addresses and IDs; the screenshots show synthetic seeded opportunities and no browser/account chrome. The three PNGs had no text or EXIF chunks. No private content was identified in the material examined. The 20-hour reminder visible in a screenshot is already present in the accepted demo UI and project sheet. This human review is bounded to the listed files, not the entire repository or any future candidate archive; a passing secret scan alone cannot establish privacy. Review the exact staged files and generated assets again before publication, and stop for an explicit remediation decision if private content is found.

## Historical Phase 1 Scope

The original local-only slice parsed `.eml` fixtures and persisted workspace-scoped imports and quarantine metadata without network calls. Its original exclusions of IMAP, scoring and a dashboard applied **only to Phase 1**; those capabilities were added in later phases. Fixed-price parsing, marketplace HTTP enrichment, AI triage and proposal automation remain out of scope.

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

To stop the local test database without deleting either database's volume:

```bash
docker compose stop mariadb_test
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

For a shared synthetic demonstration, use the clean-checkout setup above or configure a separate disposable MariaDB database whose name ends in `_demo`, disable mailbox intake, migrate it, and run the guarded seeder:

```dotenv
OPPORTUNITY_REVIEW_MODE=demo
OPPORTUNITY_REVIEW_DEMO_USER_ID=41001
OPPORTUNITY_MAILBOX_ENABLED=false
DB_DATABASE=freelance_opportunity_triage_platform_demo
```

```bash
APP_ENV=demo php artisan migrate --env=demo --force
APP_ENV=demo php artisan db:seed --env=demo --class=ReviewDemoSeeder --force
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
