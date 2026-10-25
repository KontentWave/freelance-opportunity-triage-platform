# Phase 5 Candidate, Host Smoke and Rollback

This procedure promotes one CI-built archive. Deployment and release dispatch require explicit operator approval. See the [Phase 4 review runbook](phase4-review-dashboard.md) for demo setup, isolation, browser and accessibility checks; do not reset a private database. Earlier host smoke of `65bf4ea` is historical and does not qualify a new archive.

## Current public demo (2026-10-25)

**Portfolio entry:** [https://fotp.kontentwave.com/demo](https://fotp.kontentwave.com/demo). By explicit operator choice, `/` keeps the published application's `/opportunities` redirect and guest login behavior; use `/demo` in portfolio links. No root-route application change or hosting redirect was introduced. The former public `fotp-stage.zafo-forum.sk` demo is retired with HTTP 410, not redirected. Dated September deployment/publication records below remain historical evidence.

### Approved move and isolation

The operator approved a separate-account deployment and the configuration-only rollback before the document-root switch. Authorized SSH inspection established that the new account initially contained only the provider's placeholder, not Laravel or an application database. Public A/AAAA records resolved to `37.9.175.195` / `2a00:4b40:aaaa:2009::7`; HTTPS presented a valid Let's Encrypt certificate covering `*.kontentwave.com`, valid October 25, 2026 through January 23, 2027. HTTP redirected to HTTPS. A temporary nonce-bearing probe in the actual domain directory confirmed web PHP **8.4.27** and `pdo_mysql` after the operator configured PHP 8.4; the probe was removed and its URL then returned 404.

The existing published `v1.0.0` archive, candidate `a00803b5947b7f52e18537e46edab86a949331da`, was downloaded as the original four release assets and strictly checksum-verified locally and after transfer. Archive SHA-256 remains `725c7b171882b7577c4229b1166eed4dc92d9c0c4dc541d86f53a34f2cdd6c78`. Locked production Composer dependencies and platform checks passed under PHP 8.4.27. No application source or compiled assets were changed, rebuilt or overwritten; no new release was published.

The new account's independently verified, initially empty MariaDB **11.4.13** database ends in `_demo`; its user has only usage plus privileges scoped to that database. Database names and credentials remain host-only. A guarded, consistent snapshot of the former synthetic demo was imported into this empty database, not into private production. Source checks required the known synthetic users/workspaces/provider, demo-only review provenance, and empty intake tables. Sessions, password-reset tokens, caches, locks and job data were excluded. All eight application-table counts/digests matched after import; the original two demo reviews and one enrichment were preserved. The old source's eight application-table digests were checked unchanged before retirement. No migrations, seeders, database resets or private database writes were run.

```text
~/apps/fotp-demo/
  incoming/                         # original four published release assets
  releases/<deployed-sha>/           # extracted archive and locked production dependencies
    .env -> <account-home>/apps/fotp-demo/shared/.env
    storage -> <account-home>/apps/fotp-demo/shared/storage
  current -> releases/<deployed-sha>
  shared/                           # 0700, demo only
    .env                            # 0600, fresh demo application key and database credentials
    storage/                        # independent writable runtime state
  recovery-20261025/                 # 0700, protected snapshot and operator evidence
    original-document-root/         # retained original provider placeholder
    demo.env-before-cutover         # 0600, new demo configuration recovery copy
    snapshot.json
    synthetic.sql                   # 0600, synthetic only, no sessions/cache/job data
    import-result.json
    cutover.json
    smoke.json
~/kontentwave.com/sub/fotp
  -> <account-home>/apps/fotp-demo/current/public
```

Effective settings are `APP_URL=https://fotp.kontentwave.com`, `APP_ENV=production`, `APP_DEBUG=false`, `OPPORTUNITY_REVIEW_MODE=demo`, `OPPORTUNITY_MAILBOX_ENABLED=false`, database-backed sessions, `SESSION_COOKIE=fotp-public-demo-session`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null`, HTTP-only cookies and SameSite Lax. The demo has a fresh key and its own database, configuration and writable storage; no private profile, mailbox credentials or private storage were copied. There is no `public/storage` link. Configuration remains uncached. Keep these boundaries for later deployments; do not use the private site's shared directory or retained recovery snapshots as demo state.

### Verification and retirement

At **2026-10-25T12:38:33.823Z**, a standalone real Chromium journey against this HTTPS host passed without invoking the repository browser suite's destructive `_test` setup:

- Login page 200; credential-free demo session and expected queue; pagination 25 then 1.
- Imported synthetic feedback visible; scoped detail; preset enrichment and demo feedback persisted after reload.
- Foreign-workspace JSON detail 404; a tokenless API-context write rejected with 419; logout followed by protected JSON 401 and protected HTML redirect to login.
- Secure, HTTP-only, SameSite Lax session cookie scoped to the exact public hostname.
- **33 captured browser requests, zero outside-origin requests, zero page errors.** This is browser evidence, not a server-side egress capture.

Post-smoke counts were 2 synthetic workspaces, 2 users, 28 opportunities, 27 evaluations, 3 demo reviews and 2 enrichments; imports, mailbox checkpoints/messages/runs and real-provenance reviews were empty. The original reviews/enrichment still matched their snapshot digests after the new synthetic smoke writes.

At **2026-10-25T12:42:51.768Z**, a second browser session after rollback/restoration and old-host retirement again verified demo entry, retained feedback and logout, with 13 captured requests and zero outside-origin requests. Final public checks returned 200 for the new `/demo`, 410 for both the retired demo entry and `POST /demo-session`, provider-block status 466 for the new `/.env`, and 404 for `/storage/logs/laravel.log`.

After new-host acceptance, only the former demo's public-root symlink was atomically replaced with a host-only HTTP 410 tombstone. Its `/`, `/demo` and `/opportunities` returned 410 with no redirect. The old application, configuration, storage, database, snapshot and original public-root link remain retained under protected operator storage; DNS/TLS and the vhost were not deleted. Re-enabling that public application is a separate deliberate recovery decision, not an automatic redirect or data synchronization.

Private read-only checks at **2026-10-25T12:41:11Z** retained `v1.0.0`, private mode, the existing non-demo database, disabled debug/intake and the REL-04 dedicated shared environment/storage links. Private HTTPS login 200, demo 404 and guest JSON 401 passed again. No private user provisioning, profile change, scheduler change, application switch or authenticated private-data write occurred. Phases 1-5 and the REL-01/REL-03/REL-04 closures remain unchanged; this move neither renews provider-backup evidence nor changes the unsuccessful feasibility/calibration verdict.

### Configuration-only rollback and retained limits

The new domain's document-root rollback was **exercised**: the retained original placeholder directory was briefly restored, its public placeholder response checked, and the active demo symlink restored. The demo returned 200 afterward and review/enrichment digests remained unchanged. This rehearsed hosting mapping rollback, not a new code-version rollback; the September code/assets rollback evidence retains its original scope.

For the same configuration rollback, resolve and verify the paths above first. Move only the new domain's active public-root symlink to a new non-colliding recovery name, then restore `original-document-root/` to that domain path. To return to the demo, preserve the placeholder back in recovery and atomically reinstall the verified demo public-root symlink. Keep the current demo environment, key, database and writable storage throughout; do not restore stale state, reset data, run down migrations or touch private paths. The saved environment is recovery material, not permission to replace newer configuration. Protected exact mapping/state records reside on the host. Reactivating the retired former domain requires an explicit operator decision and its saved original link; it is not necessary to preserve or inspect the new demo's data.

Server-side outbound traffic, authenticated private-user re-verification, provider worker internals and off-host recovery of the new demo were not established by this move. The local `ReviewDemoTest` attempt could not connect to the disposable database on port 3307 (5 setup failures); the installed Compose shim reports that Docker Desktop WSL integration is unavailable. It was not redirected at either deployed database. Live HTTPS/browser checks above passed independently. The existing published release and historical CI results were reused, not represented as a new protected-CI run.

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

For an existing synthetic demo update, link the same verified demo environment and storage while the new release is inactive. Compare its migration inventory and applied schema before deciding whether any additive migration is required; when unchanged, do not run migrations. Do not reseed/reset an existing demo or replace feedback, sessions or runtime state. Review the complete changes since the currently deployed release, including intervening dependency updates, rather than treating a patch as containing only its latest UI change.

## Smoke and evidence

After the switch, confirm the public `https://<approved-host>/demo` entry. In a browser with request capture enabled, enter the synthetic demo, verify the assigned workspace queue (25 then 1), open a detail, deny a foreign workspace detail with 404, select an allowlisted preset to re-evaluate, save feedback and reload to confirm it remains, then log out and confirm protected JSON returns 401. Record zero marketplace, mailbox and non-origin requests; if that cannot be established, stop. Use only synthetic screenshots and counts. Do not use the Playwright suite against `_demo`: its setup recreates `_test`.

Record UTC smoke time (ISO-8601), full candidate SHA, CI run/attempt, the printed **archive** SHA-256, database name suffix, synthetic workspace/opportunity/review/enrichment counts and pass/fail outcomes in a private operator record. Retain only sanitized evidence. The first real calibration remains unsuccessful; this is an engineering demo, not a feasibility GO. The initial release reused accepted Phase 4 accessibility evidence because it added no UI/intake behavior. For later UI patches, repeat the affected keyboard, narrow-screen, zoom and layout checks against the exact host archive; do not represent the old acceptance as proof of new UI behavior. A layout-only patch does not require a mailbox soak and does not authorize intake.

## Rollback rehearsal and restoration

On the disposable demo deployment only, confirm its `_demo` database and disabled mailbox setting, then record deterministic digests of the current synthetic review and enrichment rows. Run this read-only command from each release directory against the **same** database, before rollback, on the previous known-good code/assets, and after restoring the candidate:

```bash
php artisan tinker --execute 'foreach (["opportunity_reviews", "opportunity_enrichments"] as $table) { $rows = Illuminate\Support\Facades\DB::table($table)->orderBy("id")->get(); echo $table." ".$rows->count()." ".hash("sha256", $rows->toJson()).PHP_EOL; }'
```

Keep the digests and counts in the private operator record, not public logs. Switch **only** the active code and compiled assets to the previous known-good release while retaining the same host-only config and database; compare both digests. Switch back to the candidate, repeat the check and the demo entry/feedback reload smoke. If schema compatibility with the previous code cannot be established, stop before switching and record rollback as pending. Do not run destructive down migrations or alter private data.

## Publication and partial failure

Only after a passing smoke of this exact archive and rollback rehearsal, dispatch [the release workflow](../../workflows/release.yml) **from main** with `ci_run_id`, an explicitly approved unused stable `v1.0.<patch>` version (for example `v1.0.1`), `target_host_smoke_passed=true`, `smoke_archive_sha256` and `smoke_verified_at` from the operator record. Versions with leading-zero patch numbers, prereleases, build metadata or a different major/minor are rejected. The initial `v1.0.0` remains immutable and cannot be reused. This is explicit publication approval, not a workflow host inspection. The workflow checks the latest completed CI attempt, all three successful jobs, ancestry from main, the matching unexpired four-file artifact, checksums, inventory, smoke time/hash and absence of the selected tag/release before creating a draft and publishing it. The selected version is used consistently for existence checks, draft creation/readback, publication and final tag verification. All portfolio publications are serialized. The published assets are the files downloaded from that CI attempt, not a rebuild.

If the publication job fails after draft/tag creation, **do not retry automatically**. Set `VERSION` to the approved version, then inspect with `gh release view "$VERSION" --repo KontentWave/freelance-opportunity-triage-platform --json isDraft,tagName,targetCommitish,assets` and `gh api "repos/KontentWave/freelance-opportunity-triage-platform/git/ref/tags/$VERSION"`; compare all four assets and checksums against the recorded candidate. Resolve mismatches manually with an explicit decision. To deliberately resume a matching draft after verifying its assets, an authorized operator may run `gh release edit "$VERSION" --repo KontentWave/freelance-opportunity-triage-platform --draft=false`. Never force-move/delete a tag or overwrite release assets as a retry. A token-created tag does not trigger another validation workflow.

**Staging smoke record (2026-09-28):** Candidate `a00803b5947b7f52e18537e46edab86a949331da`, CI run `36245328584` attempt 1, archive SHA-256 `725c7b171882b7577c4229b1166eed4dc92d9c0c4dc541d86f53a34f2cdd6c78` passed protected CI and was installed at isolated `fotp-stage.zafo-forum.sk`. The effective PHP 8.4 CLI configuration, independently verified empty `fotp_1_demo` MariaDB connection, additive migration and guarded synthetic seed passed. The operator confirmed Apache 2.4 / PHP 8.4 for the stage in hosting settings; the web-process version was not probed directly. HTTPS browser smoke passed entry, scoped queue/detail, preset enrichment, demo feedback persistence, foreign 404, logout and guest 401. Request capture of the save/reload/logout journey observed zero outside-origin requests. Post-smoke counts: 2 synthetic workspaces, 2 users, 28 opportunities, 27 evaluations, 2 demo reviews, 1 enrichment, zero email imports and mailbox records. The original app link was unchanged. The subsequently prepared previous-code rollback rehearsal passed as recorded below. Publication was separately approval-gated and had not occurred at this checkpoint; the later publication record follows.

**Candidate review and rollback record (2026-09-28T18:19:05Z):** Strict checksum verification passed for the three checksummed assets in the four-file CI download and confirmed the archive hash above. A bounded review of the archive's 380-entry inventory, source and compiled assets for secret patterns, and all three synthetic screenshots found no private content in the examined material. Only permitted example environment files and empty private-storage scaffolding appeared in the inventory; this does not prove all possible secrets absent. In the isolated stage, an inactive release of previously host-smoked `65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c` was prepared from its clean tracked source, compiled assets and locked dependencies, linked only to the stage's shared configuration/storage. Both code versions saw the same `fotp_1_demo` schema and disabled mailbox. Review and enrichment counts/digests matched before the code switch, on previous code/assets, and after restoring the candidate (2 reviews, 1 enrichment). Both code versions returned HTTPS 200 at `/demo`; after restoration, browser reload preserved the existing synthetic feedback and confirmed-detail basis. No migrations, database reset, private app switch or publication were performed. The original app remains unchanged and the exact candidate is active on stage.

**Publication and verification record (2026-09-28):** With separate operator approval, [workflow run 36464920838](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/36464920838) completed the validation and publication jobs successfully. [v1.0.0](https://github.com/KontentWave/freelance-opportunity-triage-platform/releases/tag/v1.0.0) was published at 2026-09-28T18:24:45Z, not as a draft or prerelease. The tag directly targets `a00803b5947b7f52e18537e46edab86a949331da`. Exactly four release assets were downloaded and compared byte-for-byte with the checked CI download; the three assets listed in `SHA256SUMS` passed strict verification, including the exact archive SHA-256 `725c7b171882b7577c4229b1166eed4dc92d9c0c4dc541d86f53a34f2cdd6c78`. The published notes contain the candidate, CI run/attempt, attested smoke time and installation/rollback and portfolio links. This completes the Phase 5 portfolio release; the private `fotp` app and its database were not switched at this checkpoint. Post-publication documentation was merged later in [PR #33](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/33).

**Private-site deployment record (2026-09-28):** After separate approval, read-only preflight confirmed the original `~/apps/fotp` was a tracked-clean private-mode installation at `65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c`, using its own non-demo database, a readable private profile, disabled mailbox intake and debug, and all 16 migrations applied. The candidate has no additional migrations. A host-only private database dump outside the webroot completed with 19 table definitions and a completion marker, and its checksum and `0700` directory / `0600` file permissions were checked; no restore was tested. The same published archive passed its recorded SHA-256 check after transfer, was extracted into `~/apps/fotp-private-releases/a00803b5947b7f52e18537e46edab86a949331da`, and received locked production dependencies under PHP 8.4.25. It booted with the existing private environment and storage, zero pending migrations and passing loopback 200 login / 404 demo / 401 guest JSON checks. The old installation was retained at `~/apps/fotp-before-v1-65bf4eaee6cc5d0d7eb0cc17527fe12d67e0983c`; `~/apps/fotp` now links to the independent candidate, whose `.env` and `storage` point into the retained installation. HTTPS checks on the live private host passed the same three response codes and served a compiled asset with 200. The private profile passed the application's loader. `~/apps/fotp-stage/current` still targets the candidate on its separate synthetic demo database. No migrations or private database reset were run. An authenticated private-user journey and a database restore remain untested. These post-release documentation edits were merged in [PR #33](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/33), not included in the published tag.

## Private database backups and health checks (REL-03)

**Verified 2026-10-12:** recurring provider database backups satisfy REL-03; see the [dated evidence and provenance](../audit/fixes/04-reliability-dependability-fixes.md#closure-entry--2026-10-12-recurring-provider-database-backups). Historical release records above retain their original scope. No application-managed backup job was added. Actual database, service and connection identifiers belong only in protected operator configuration, not this runbook.

### Coverage and repeatable backup check

1. Sign in to the provider's protected hosting panel with access to the correct service. Open **Services -> `<private-service>` -> Advanced configuration -> Databases -> `<private-database>` -> menu -> Backups**. Privately compare the selected database with the application's effective connection, not a demo/test database or website-files backup.
2. Confirm automatic backups remain every 24 hours with at-most-14-day availability. The panel policy says databases larger than 2 GB are backed up only weekly; check database size periodically and after substantial growth. If the exception applies, establish adequate daily coverage separately rather than silently accepting weekly protection.
3. Check the latest available restore point, daily continuity and any failure notices **daily and before risky operational work**. Record its timestamp and timezone, selection/coverage confirmation and PASS / FAIL privately. A restore-point list is provider evidence, not independently examined job logs. Clarify the panel timezone before using precise age calculations.
4. Treat a missing restore point, explicit failure, daily gap or newest point older than **36 hours** as a failed operational health check; contact provider support and record follow-up. The 36-hour alert threshold is an operator check, not a provider SLA. Do not infer 15-day retention from 15 listed entries.
5. For an on-demand backup, use **Create current backup**, wait for the new available point and verify its displayed timestamp. This is manual execution and must not be reported as proof of recurrence.

The [provider restoration policy](https://www.websupport.sk/podpora/kb/obnova-databazy/) documents daily backups, 14-day retention and panel restoration. For retention beyond 14 days, the panel permits SQL download; use a trusted private destination outside webroots/repositories/releases, a `0700` directory and `0600` files, and compute a checksum there. Never paste/download backup contents into chat or commit them. No long-term/off-host archive was established by REL-03.

### Restoration procedure and access

- Required: authenticated panel access authorized for the correct service/database. Importing an own-file backup also requires authorized database-administration access; obtain the current endpoint and connection details privately from the panel. Keep credentials out of command arguments/logs and use a protected client authentication file when using CLI tools.
- **Preferred verification/recovery preparation:** in the backup list, choose the selected point's menu -> **Create database from backup**, following the [provider's isolated-copy procedure](https://www.websupport.sk/podpora/kb/kopirovanie-databaz/). Select a new, explicitly disposable `<restore-verification-database>` with its own access and the compatible MariaDB version/encoding. Verify the target does not already exist; never overwrite or repoint production. Keep the copy non-public and protect any private contents.
- Use a separate non-public restore environment/configuration. Keep mailbox intake, schedules, workers and all external integrations disabled before booting application tooling; do not link production writable storage/configuration or expose the copied application through a public document root. Confirm the effective disposable database target before every check. Never run resetting tests, seeders or migrations against production.
- Verify schema/table/engine inventory and aggregate row-count/integrity evidence against a checkpoint for the **selected backup**, where available. A current production count may legitimately differ from an older backup. Check foreign-key/orphan integrity and safe read-only application queries for the operator/workspace, queue/detail, enrichment and feedback; do not print private content or execute login/write flows merely to test restoration. Record which checks passed and any unavailable comparison as NOT VERIFIED. Remove only the explicitly named disposable database/configuration/resources created for the rehearsal.
- **Live recovery is a separate operation requiring approval:** first preserve current database state, plan writer quiescence and schema/code compatibility, then use the chosen point's **Restore** action for the intended database. It replaces database state; this task did not execute it. Do not delete the original database: the provider warns its deleted name cannot be reused. Database restoration does not restore source files, environment or storage, so recover those separately if needed. Do not relocate the existing production `.env` or `storage/` as part of REL-03.

Provider restoration was **not tested** on October 12. The historical isolated restore proves only the September 28 manual dump. Provider consistency internals, recovery SLA and storage separation from the production host/account remain **NOT VERIFIED**; request provider confirmation before claiming host-loss protection. The same-host historical manual backup cannot protect against loss of that host. Daily backups can lose changes since the last point and are not long-term archives. Keep mailbox intake disabled and leave Laravel's scheduler unchanged.

## Private shared configuration and storage (REL-04)

**Current layout verified 2026-10-13:** the private release remains `v1.0.0` / `a00803b5947b7f52e18537e46edab86a949331da`. See the [REL-04 closure evidence](../audit/fixes/04-reliability-dependability-fixes.md#closure-entry--2026-10-13-copy-first-shared-layout-relocation). The September 28 observation that live state resided inside the retained rollback installation is historical, not the current deployment layout.

```text
~/apps/fotp -> fotp-private-releases/<deployed-sha>
~/apps/fotp-private-releases/<deployed-sha>/.env
  -> <account-home>/apps/fotp-private-shared/.env
~/apps/fotp-private-releases/<deployed-sha>/storage
  -> <account-home>/apps/fotp-private-shared/storage
~/apps/fotp-private-shared/                   # 0700, private production only
  .env                                      # 0600
  storage/app/private/triage/                # 0700
    <private-profile>.json                  # 0600
  storage/{app,framework,logs}/              # preserved runtime access modes
~/fotp-rel04-recovery-<date>/                 # 0700, protected recovery records/copies
~/apps/fotp-before-v1-<previous-sha>/         # retained code and old recovery snapshots
```

Use verified absolute paths for release links; do not embed literal `~` or placeholders in symlink targets. Shared state is outside public webroots and all code-release/rollback directories. Staging/demo retain their separate configuration/storage and database. There is no private deployment `public/storage` link; do not introduce a public link to private storage.

### Future private deployments and code rollback

1. Resolve the actual private target and verify the approved release, PHP executable, database, profile and writer inventory afresh. The verified production CLI is `/usr/bin/php8.4` (8.4.25 on October 13); do not substitute another default PHP or assume a later host configuration is identical.
2. Link **every** new private release's `.env` and `storage` to the current dedicated private shared directory before booting/switching it. Do not copy the old rollback installation's stale state into a new release. Preserve ownership and required runtime access; environment/profile stay `0600`, shared parent and profile directory `0700`, never `0777`.
3. For a code rollback, keep the same current shared environment, storage and database. If the retained older installation has regular `.env`/`storage` entries rather than links, first inspect and preserve them under new, non-colliding recovery names, then install verified shared links while that code is inactive. This preparation needs operational authorization; do not delete the snapshots or switch active code first. Establish code/schema compatibility without destructive migrations.
4. Inspect absolute paths, internal symlinks and effective configuration, including `view.compiled`, profile, sessions, logging, file-cache/lock, maintenance and filesystem roots. On October 13 configuration was uncached, the profile used the active application alias and Laravel recomputed the physical compiled-view path after the storage switch; no environment edit or cache clear was needed. If a later deployment caches paths, rebuild only the affected cache with the verified PHP after correcting those references. Never blanket-clear sessions, database caches, locks or application data.
5. Account for long-running processes and PHP path caching; verify they use the shared targets after a switch and restart only this application's affected processes when necessary. Preserve scheduler state and disabled mailbox intake. Verify boot, private profile/access, HTTPS login 200, private demo 404 and guest protected JSON 401; with an existing authenticated session, read only the retained REL-01 synthetic queue/detail. Do not create another fixture or evaluate/save it for a layout check.
6. Treat retained old state as recovery material, not an alternative current configuration. Do not delete/archive retained releases merely because REL-04 is closed; cleanup requires separate authorization and dependency checks.

### Layout-failure recovery

Rollback was **prepared but not exercised** on October 13. Original link targets, permissions/ownership, exact cron, prior maintenance state and loopback-process state are in the protected host-only recovery directory, alongside the environment and complete quiesced storage copy. Both original rollback copies remain in place. These same-host copies do not replace REL-03 database backups or protect against host/account loss.

1. Resolve current links and consult those private records. Quiesce this application's writers again: maintenance without bypass, pause only its scheduler/affected processes, and wait/check for active work and stable storage. Maintenance blocks new application requests; it does not itself drain active requests or stop workers/scheduled commands. Verify both HTML and JSON barriers; do not stop shared hosting services.
2. Before restoring anything, preserve a new protected copy of the **latest** shared environment/storage and identify changes since cutover. Reconcile newer files, updates and intentional deletions into the intended recovery target, including any session/cache/lock implications. Never replace newer data with the pre-cutover snapshot or run a blind destructive synchronization.
3. Correct only relocation-related paths and install the recorded original links using temporary links and atomic replacement while writers remain controlled. Keep maintenance effective in both old/new trees until both links and any affected cache agree. Do not replace application code/key/database settings or restore the database as part of layout recovery.
4. Boot and verify effective private settings, selected database, profile, required directories/read-write access and targeted HTTP checks. Restore the recorded maintenance state and exact scheduler/process state, then recheck service and disabled intake. Recovery storage from the final synchronization contains task-created maintenance markers; restore the prior state deliberately, not by exposing that snapshot unmodified.
5. If verification still fails, remain in controlled application maintenance, retain both latest and original material and report REL-04 pending with exact remaining steps. Record actual rollback execution separately from preparation; do not claim a rehearsal occurred.

**October 13 maintenance observation:** copy-first synchronization and atomic link replacement passed during a roughly 62-second application/control window. The original scheduler and two private loopback-server processes were restored. Essential host checks passed; the optional authenticated read-only repeat was unavailable because the trusted browser was signed out. No code release, data migration or intake operation occurred.
