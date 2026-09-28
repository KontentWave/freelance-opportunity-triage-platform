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

**Staging smoke record (2026-09-28):** Candidate `a00803b5947b7f52e18537e46edab86a949331da`, CI run `36245328584` attempt 1, archive SHA-256 `725c7b171882b7577c4229b1166eed4dc92d9c0c4dc541d86f53a34f2cdd6c78` passed protected CI and was installed at isolated `fotp-stage.zafo-forum.sk`. The effective PHP 8.4 CLI configuration, independently verified empty `fotp_1_demo` MariaDB connection, additive migration and guarded synthetic seed passed. The operator confirmed Apache 2.4 / PHP 8.4 for the stage in hosting settings; the web-process version was not probed directly. HTTPS browser smoke passed entry, scoped queue/detail, preset enrichment, demo feedback persistence, foreign 404, logout and guest 401. Request capture of the save/reload/logout journey observed zero outside-origin requests. Post-smoke counts: 2 synthetic workspaces, 2 users, 28 opportunities, 27 evaluations, 2 demo reviews, 1 enrichment, zero email imports and mailbox records. The original app link was unchanged. The subsequently prepared previous-code rollback rehearsal passed as recorded below. Publication was separately approval-gated and had not occurred at this checkpoint; the later publication record follows.

**Candidate review and rollback record (2026-09-28T18:19:05Z):** Strict checksum verification passed for the three checksummed assets in the four-file CI download and confirmed the archive hash above. A bounded review of the archive's 380-entry inventory, source and compiled assets for secret patterns, and all three synthetic screenshots found no private content in the examined material. Only permitted example environment files and empty private-storage scaffolding appeared in the inventory; this does not prove all possible secrets absent. In the isolated stage, an inactive release of previously host-smoked `65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c` was prepared from its clean tracked source, compiled assets and locked dependencies, linked only to the stage's shared configuration/storage. Both code versions saw the same `fotp_1_demo` schema and disabled mailbox. Review and enrichment counts/digests matched before the code switch, on previous code/assets, and after restoring the candidate (2 reviews, 1 enrichment). Both code versions returned HTTPS 200 at `/demo`; after restoration, browser reload preserved the existing synthetic feedback and confirmed-detail basis. No migrations, database reset, private app switch or publication were performed. The original app remains unchanged and the exact candidate is active on stage.

**Publication and verification record (2026-09-28):** With separate operator approval, [workflow run 36464920838](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/36464920838) completed the validation and publication jobs successfully. [v1.0.0](https://github.com/KontentWave/freelance-opportunity-triage-platform/releases/tag/v1.0.0) was published at 2026-09-28T18:24:45Z, not as a draft or prerelease. The tag directly targets `a00803b5947b7f52e18537e46edab86a949331da`. Exactly four release assets were downloaded and compared byte-for-byte with the checked CI download; the three assets listed in `SHA256SUMS` passed strict verification, including the exact archive SHA-256 `725c7b171882b7577c4229b1166eed4dc92d9c0c4dc541d86f53a34f2cdd6c78`. The published notes contain the candidate, CI run/attempt, attested smoke time and installation/rollback and portfolio links. This completes the Phase 5 portfolio release; the private `fotp` app and its database were not switched. Post-publication documentation changes remain local until separately committed.

**Private-site deployment record (2026-09-28):** After separate approval, read-only preflight confirmed the original `~/apps/fotp` was a tracked-clean private-mode installation at `65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c`, using its own non-demo database, a readable private profile, disabled mailbox intake and debug, and all 16 migrations applied. The candidate has no additional migrations. A host-only private database dump outside the webroot completed with 19 table definitions and a completion marker, and its checksum and `0700` directory / `0600` file permissions were checked; no restore was tested. The same published archive passed its recorded SHA-256 check after transfer, was extracted into `~/apps/fotp-private-releases/a00803b5947b7f52e18537e46edab86a949331da`, and received locked production dependencies under PHP 8.4.25. It booted with the existing private environment and storage, zero pending migrations and passing loopback 200 login / 404 demo / 401 guest JSON checks. The old installation was retained at `~/apps/fotp-before-v1-65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c`; `~/apps/fotp` now links to the independent candidate, whose `.env` and `storage` point into the retained installation. HTTPS checks on the live private host passed the same three response codes and served a compiled asset with 200. The private profile passed the application's loader. `~/apps/fotp-stage/current` still targets the candidate on its separate synthetic demo database. No migrations or private database reset were run. An authenticated private-user journey and a database restore remain untested. These post-release documentation edits are local, not part of the published tag.
