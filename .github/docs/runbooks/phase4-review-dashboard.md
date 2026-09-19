# Phase 4 Review Dashboard Runbook

## Safety boundary

- Private and demo deployments use separate databases.
- A demo database name must end in `_demo`; automated browser tests use `_test`.
- Never put private profiles, descriptions, notes, credentials, or genuine opportunity content in commands, logs, screenshots, or published evidence.
- Keep `OPPORTUNITY_MAILBOX_ENABLED=false` in demo mode. The demo performs no mailbox or marketplace requests.
- Back up private data before migrations. Do not use `migrate:fresh` on a private database.

## Private deployment

1. Set `OPPORTUNITY_REVIEW_MODE=private` and configure `OPPORTUNITY_REVIEW_PROFILE_PATH` as a server-only absolute path to the personal profile.
2. Run `php artisan migrate --force`.
3. Provision a Laravel user through trusted operator tooling, hash its unpublished password, and assign its `workspace_id` explicitly. Do not infer workspace ownership from email.
4. Run `npm ci && npm run build`; deploy the compiled `public/build` assets. Node is not a production service.
5. Cache production configuration only after verifying the private profile path and session/database settings.

Private mode must return 404 for `GET /demo` and `POST /demo-session`. Invalid or blank profile configuration must fail safely rather than loading the synthetic profile.

## Synthetic demo deployment

Use a separate disposable database containing no private records:

```dotenv
OPPORTUNITY_REVIEW_MODE=demo
OPPORTUNITY_REVIEW_DEMO_USER_ID=41001
OPPORTUNITY_MAILBOX_ENABLED=false
DB_DATABASE=freelance_opportunity_triage_platform_demo
```

After independently confirming the database name and connection:

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=ReviewDemoSeeder --force
npm ci
npm run build
```

The seeder creates two isolated workspaces, assigned users, and 28 fixed opportunities. It refuses unsafe modes, database suffixes, enabled mailbox intake, and unrelated records. Rerunning it verifies existing fixtures and does not overwrite them.

## Safe demo reset

1. Confirm `OPPORTUNITY_REVIEW_MODE=demo`.
2. Confirm the connected database name ends in `_demo` and contains no private records.
3. Confirm mailbox intake is disabled.
4. Run `php artisan migrate:fresh --force` followed by `php artisan db:seed --class=ReviewDemoSeeder --force`.

Never repoint a demo deployment at the private database, even temporarily.

## Local acceptance

```bash
cp .env.testing.example .env.testing
docker compose up -d mariadb_test
composer validate --strict
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
composer audit --locked
vendor/bin/phpunit
npm ci
npm run build
npm audit
npx playwright install chromium
npm run test:e2e
git diff --check
```

The browser suite runs Chromium only, one worker, private mode before demo mode, compiled assets, and the disposable `_test` database.

## Manual accessibility sweep

- Enter and complete the review journey with keyboard only; verify visible focus and logical order.
- At 320 CSS pixels and simulated 200% zoom, verify primary controls remain usable without page-level horizontal scrolling.
- Trigger validation, stale-context, offline, and expired-session failures; verify the draft remains in memory, no success is announced, the error receives focus, and retry is available only after completion.
- Verify script-like description text remains text and no remote resource loads.
- Verify demo mode exposes only preset enrichment, enum feedback, and the fixed/empty note choices.

## Target-host smoke

Deployment requires explicit approval and target-host access. After deployment, record the candidate commit and synthetic counts only, then verify:

1. Private login or demo entry reaches the expected isolated workspace.
2. Queue to detail navigation works by keyboard.
3. A confirmed-field preset re-evaluates the synthetic opportunity.
4. Feedback saves, survives reload, and remains `sample_kind=demo` in demo mode.
5. Logout invalidates access.
6. Browser network records show no mailbox, marketplace, or non-origin request.

Do not claim Phase 4 complete until protected CI and this target-host smoke pass.

## Rollback

Deploy the prior application assets and code while preserving additive review data. Do not run destructive down migrations against private data. A demo database may be discarded only after re-verifying its `_demo` identity.
