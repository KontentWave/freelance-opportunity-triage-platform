# Phase 5 Candidate, Host Smoke and Rollback

This procedure promotes one CI-built archive. Deployment and release dispatch require explicit operator approval. See the [Phase 4 review runbook](phase4-review-dashboard.md) for demo setup, isolation, browser and accessibility checks; do not reset a private database. Earlier host smoke of `65bf4ea` is historical and does not qualify a new archive.

## Download and identify

On a trusted operator machine with `gh`, `tar` and `sha256sum`, set the successful **push-to-main** CI run ID and its full SHA/attempt from the run page:

```bash
CI_RUN_ID=<positive-run-id>
SHA=<full-40-character-sha>
ATTEMPT=<latest-completed-attempt>
mkdir -p candidate-download
gh run download "$CI_RUN_ID" --repo KontentWave/freelance-opportunity-triage-platform --name "release-candidate-$SHA-$ATTEMPT" --dir candidate-download
cd candidate-download
sha256sum --check --strict SHA256SUMS
ARCHIVE="freelance-opportunity-triage-platform-$SHA.tar.gz"
sha256sum "$ARCHIVE"
tar -tzf "$ARCHIVE" | less
```

Check that there are **exactly four regular files** in the download: the archive, `sbom.cdx.json`, `candidate.json` and `SHA256SUMS`. Confirm the manifest's repository, SHA, run and attempt, the Syft build/dependency scope and the three required checks on that _completed_ CI attempt. Do not substitute a branch download, another attempt or the older smoke. Examine the exact archive and screenshots for private material before hosting; a scanner is not a privacy guarantee.

## Isolated host deployment

Use an approved HTTPS host and a separate synthetic MariaDB database ending `_demo`. Prepare a shared host-only environment with `APP_DEBUG=false`, `APP_URL=https://<approved-host>`, `OPPORTUNITY_REVIEW_MODE=demo`, `OPPORTUNITY_MAILBOX_ENABLED=false`, a fresh private `APP_KEY`, and the isolated `_demo` connection. Keep this file outside the release directory; never archive or publish it. Verify TLS, document root pointing at `public/`, and filesystem/session permissions using the Phase 4 runbook. Do not place mailbox or marketplace credentials on this deployment.

On the host, after independently checking its PHP 8.4 binary and database target, deploy a **verified** archive into a new immutable directory. Example from a release parent owned by the operator:

```bash
mkdir -p "releases/$SHA"
tar -xzf "$ARCHIVE" -C "releases/$SHA"
cd "releases/$SHA"
ln -s ../../shared/.env .env
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=ReviewDemoSeeder --force
```

The `shared/.env` example is relative to `releases/$SHA`; adapt the path to the approved host layout and verify the resulting link before invoking Artisan. Preserve the shared environment, database, session and storage during code switches; configure persistent writable storage outside immutable release directories. Do **not** run `npm ci`, Vite, `migrate:fresh`, a down migration, or a private database reset during deployment. The archive already contains compiled `public/build` assets. Switch the web document root/atomic current symlink only after confirming the new release boots; keep the previous known-good code and assets available.

## Smoke and evidence

After the switch, confirm the public `https://<approved-host>/demo` entry. In a browser with request capture enabled, enter the synthetic demo, verify the assigned workspace queue (25 then 1), open a detail, deny a foreign workspace detail with 404, select an allowlisted preset to re-evaluate, save feedback and reload to confirm it remains, then log out and confirm protected JSON returns 401. Record zero marketplace, mailbox and non-origin requests; if that cannot be established, stop. Use only synthetic screenshots and counts. Do not use the Playwright suite against `_demo`: its setup recreates `_test`.

Record UTC smoke time (ISO-8601), full candidate SHA, CI run/attempt, the printed **archive** SHA-256, database name suffix, synthetic workspace/opportunity/review/enrichment counts and pass/fail outcomes in a private operator record. Retain only sanitized evidence. The first real calibration remains unsuccessful; this is an engineering demo, not a feasibility GO. Accepted Phase 4 accessibility evidence applies because this release adds no UI/intake behavior; no new accessibility audit or 24-hour mailbox soak is required.

## Rollback rehearsal and restoration

On the disposable demo deployment only, confirm its `_demo` database and disabled mailbox setting, then record deterministic digests of the current synthetic review and enrichment rows. Run this read-only command from each release directory against the **same** database, before rollback, on the previous known-good code/assets, and after restoring the candidate:

```bash
php artisan tinker --execute 'foreach (["opportunity_reviews", "opportunity_enrichments"] as $table) { $rows = Illuminate\Support\Facades\DB::table($table)->orderBy("id")->get(); echo $table." ".$rows->count()." ".hash("sha256", $rows->toJson()).PHP_EOL; }'
```

Keep the digests and counts in the private operator record, not public logs. Switch **only** the active code and compiled assets to the previous known-good release while retaining the same host-only config and database; compare both digests. Switch back to the candidate, repeat the check and the demo entry/feedback reload smoke. If schema compatibility with the previous code cannot be established, stop before switching and record rollback as pending. Do not run destructive down migrations or alter private data.

## Publication and partial failure

Only after a passing smoke of this exact archive and rollback rehearsal, dispatch [the release workflow](../../workflows/release.yml) **from main** with `ci_run_id`, `version=v1.0.0`, `target_host_smoke_passed=true`, `smoke_archive_sha256` and `smoke_verified_at` from the operator record. This is explicit publication approval, not a workflow host inspection. The workflow checks the latest completed CI attempt, all three successful jobs, ancestry from main, the matching unexpired four-file artifact, checksums, inventory, smoke time/hash and absent tag/release before creating a draft and publishing it. The published assets are the files downloaded from that CI attempt, not a rebuild.

If the publication job fails after draft/tag creation, **do not retry automatically**. Inspect with `gh release view v1.0.0 --repo KontentWave/freelance-opportunity-triage-platform --json isDraft,tagName,targetCommitish,assets` and `gh api repos/KontentWave/freelance-opportunity-triage-platform/git/ref/tags/v1.0.0`; compare all four assets and checksums against the recorded candidate. Resolve mismatches manually with an explicit decision. To deliberately resume a matching draft after verifying its assets, an authorized operator may run `gh release edit v1.0.0 --repo KontentWave/freelance-opportunity-triage-platform --draft=false`. Never force-move/delete a tag or overwrite release assets as a retry. A token-created tag does not trigger another validation workflow.

**Current record:** local script/packaging checks only. CI push artifact, approved-host smoke, rollback rehearsal and publication are pending operator actions; record actual dates, counts and links only after execution.
