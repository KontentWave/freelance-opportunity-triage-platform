# Reliability and Dependability — Remediation Evidence

This report records current remediation separately from the unchanged [original reliability audit](../04-reliability-dependability.md) and [historical audit summary](../README.md). Finding IDs and accepted Phase 1–5 evidence remain unchanged. Append subsequent dated entries; do not replace earlier observations.

## REL-01 — Private operator provisioning and authenticated workflow

**Original finding:** [REL-01 in Audit 04](../04-reliability-dependability.md#confirmed-defects-by-severity).

**Current status:** **CLOSED / VERIFIED**, 2026-10-11.

**Deployed version:** `v1.0.0`, `a00803b5947b7f52e18537e46edab86a949331da`.

**Final host persistence verification:** `2026-10-11T20:07:00Z`.

### Progress entry — 2026-10-11: target and provisioning

- The initial `git status --short` was clean. Existing authorized SSH access was reused; no hosting access or deployment configuration was changed.
- The active private release directory matched the full deployed SHA. Hashing the same tracked files under `app/`, `routes/`, `config/`, `database/`, `resources/`, `bootstrap/`, plus `public/index.php` and `composer.*`, produced identical local/tag and deployed digests: `25d7ad7ca54223c33c349d6c430ff61d5b3af9a458a751acdc7d29962283ba03`. This checks those runtime files, not every installed dependency or asset.
- Effective configuration was production, debug off, private review mode, and mailbox intake disabled. The connected MariaDB version was `11.4.13`; the database had neither `_demo` nor `_test` suffix. The effective stage database was independently checked as demo mode with `_demo` suffix and a different database fingerprint. Database names and fingerprints are omitted here.
- The sole existing workspace matched the explicitly configured intake workspace. All 52 pre-existing opportunities belonged to it. The personal scoring profile loaded successfully without printing its definition.
- Fresh account checks still found zero users and zero assigned users. Those observations justified provisioning; September counts were not assumed current and did not trigger bulk evaluation or population.
- The operator executed temporary, guarded tooling through a trusted interactive SSH terminal. Both email and password inputs were hidden; Laravel-supported hashing was used. The tooling explicitly assigned the verified existing workspace, refused a mismatched existing account or concurrent provisioning, and did not use a seeder or factory password.
- The operator reported successful provisioning and entered login credentials directly in the private browser interface. A subsequent host check independently confirmed exactly one operator, its matching workspace, and a supported password hash. No existing password was reset.

### Closure entry — 2026-10-11: private-host journey

These results are from the actual private deployment, not local tests or staging acceptance.

| Check | Result | Evidence / scope |
| --- | --- | --- |
| Deployed version, private database and intended workspace | PASS | Current release, tracked runtime digest, effective configuration and workspace checks above. |
| Operator provisioned with supported hashing and explicit workspace | PASS | One assigned operator independently verified after interactive provisioning. |
| Login reaches the expected workspace queue | PASS | Operator completed direct interactive login; the shared browser reached the private review queue. Authenticated queue JSON returned 200, with 53 records after adding the single fixture. |
| Synthetic opportunity detail | PASS | Browser reached the clearly labeled REL-01 fixture in the assigned workspace. |
| Evaluate only the fixture when needed | PASS | The fixture began unscored; its browser evaluation request returned 200. Exactly one fixture evaluation persisted. |
| Save enrichment | PASS | Browser save returned 200; synthetic description and confirmed maximum hourly rate `45.00` persisted. |
| Save feedback with `sample_kind=demo` | PASS | Browser save returned 200 with demo provenance; host readback confirmed `demo`, `SKIP`, and `not_applied`. |
| Reload and verify enrichment and feedback | PASS | Browser reload preserved description, confirmed rate, label, provenance, outcome and synthetic note. JSON readback and database checks confirmed feedback references the saved enrichment. |
| Logout | PASS | Browser sign-out returned to `/login`. |
| Protected review JSON after logout | PASS | Same-browser `GET /review/v1/opportunities`, with `Accept: application/json`, returned 401 and JSON content type. The expected browser-console 401 is not an application defect. |
| Preserve original opportunity data and immutable history | PASS | Ordered aggregate row digests and counts before/after matched for all original opportunities, skills, evaluations, enrichments and reviews, excluding only the new fixture and its linked rows. |
| Calibration exclusion | PASS | Saved review provenance is `demo`; a read-only report selecting only the fixture evaluation returned `DEMO_ONLY` and zero real samples. No real cohort was assembled or modified. |
| Intake remains disabled | PASS | Effective flag rechecked after the journey. No poll, connectivity, mailbox import or marketplace operation was invoked. |
| Complete browser request-capture evidence | NOT VERIFIED | Browser event callbacks reported zero requests despite known HTTP activity, so their counters are unusable and are not evidence of zero network requests. Post-logout Resource Timing independently showed two same-origin resources and no mailbox/marketplace resource, but covers only that document, not the full journey. |

The initial verification harness incorrectly compared the filtered unscored queue count (23) with the unfiltered total (53). The corrected unfiltered check passed before browser writes. This was a verification assertion error, not an application defect; no application changes were made.

All provisioning and required authenticated workflow checks passed. The ancillary full request-capture diagnostic is explicitly not claimed as verified; it does not substitute for, or invalidate, the verified login/save/reload/logout/401 results.

### Synthetic fixture disposition

- Retained as one clearly labeled **REL-01 synthetic operator verification** opportunity, provider `synthetic_demo`, external marker `rel-01-20261011`, source template `rel-01-operational-smoke`. Its non-marketplace synthetic URL is not exposed as an actionable marketplace link.
- Final fixture history: one evaluation, one enrichment at revision 1, one review bound to that enrichment. Outcome is `not_applied`; no genuine outcome was changed. Existing history was not deleted or rewritten.
- Exclude this fixture and every linked evaluation from real calibration selections. Persisted `sample_kind=demo` additionally makes a report containing it `DEMO_ONLY`, even under a personal profile; this is not evidence of feasibility or a real calibration GO.
- Temporary provisioning tooling and aggregate-only preservation checkpoints were removed after verification. Credentials, account identifiers, private hostnames, private profile definitions and genuine opportunity content are excluded from this evidence.

## REL-03 — Recurring backup coverage

### Status entry — 2026-10-11

**OPEN / NOT VERIFIED.** The [original REL-03 finding](../04-reliability-dependability.md#confirmed-defects-by-severity) remains outstanding. This task did not verify provider snapshots, establish scheduled dumps, check rotation, or perform a new restore. The historical successful restore proves only that single dump's restorability, not recurring coverage.

Confirm and document provider snapshot coverage, or separately authorize and verify a scheduled dump with checksum and rotation, before relying on the tool day-to-day. The production-use verdict remains **CONDITIONAL GO**.

### Closure entry — 2026-10-12: recurring provider database backups

**Current REL-03 status: CLOSED / VERIFIED (provider-backup route).** See the [original REL-03 finding](../04-reliability-dependability.md#confirmed-defects-by-severity). The preceding open status is historical.

**Deployed version:** `v1.0.0`, `a00803b5947b7f52e18537e46edab86a949331da`. **Independent host verification:** `2026-10-12T11:02:22Z`; provider panel evidence and public policy checked on the same date.

#### Evidence provenance and coverage

- The initial worktree was clean. Authorized SSH read-only inspection independently found the active release directory at the full SHA above. Hashing the exact tracked inventory under `app/`, `routes/`, `config/`, `database/`, `resources/`, `bootstrap/`, plus `public/index.php` and `composer.*`, matched the local/tag runtime digest `25d7ad7ca54223c33c349d6c430ff61d5b3af9a458a751acdc7d29962283ba03`. This is not a full dependency/asset verification. An initial filesystem-wide inventory included additional files and was not comparable; the exact tracked-inventory comparison passed.
- Independently read effective production configuration: debug off, private review mode, mailbox intake disabled. The connected database was MariaDB `11.4.13-MariaDB-ubu2204-log`, with neither `_demo` nor `_test` suffix, 19 base tables, all InnoDB. Read-only metadata reported approximately 3,063,808 bytes of table/index allocation, well below the provider's 2 GB weekly-backup exception. No opportunity content, credentials or database identifiers were printed.
- The operator supplied a redacted screenshot of the **database** backup list and explicitly confirmed that the selected database is the actual private production database, not staging. That target association is **operator-attested**, not an independently authenticated panel inspection.
- The supplied image was directly inspected: available restore points on each displayed date October 3-12, latest **2026-10-12 05:01:55**, approximately 184.5 KB, each with a Restore action; 15 entries in total across two pages. The timezone is not shown and remains **NOT VERIFIED**; no UTC conversion is asserted. Page two was not inspected. Entry count is not proof of 15-day retention. Available restore points are dated provider evidence of completed backups, not independently inspected job logs or dump contents.
- The operator transcribed the panel's policy: automatic database backup every 24 hours, availability for at most 14 days, optional current-state backup and SQL download, own-file import through the provider's database administration interface, and weekly-only backups for databases larger than 2 GB.
- Independently retrieved the provider's public [database restoration policy/procedure](https://www.websupport.sk/podpora/kb/obnova-databazy/) (article modification date October 7, 2026) and [database-copy/from-backup procedure](https://www.websupport.sk/podpora/kb/kopirovanie-databaz/). The public restoration policy corroborates automatic 24-hour backups and 14-day retention; the panel transcription supplies the size exception. These are provider policy statements, not an inspection of the provider's internal scheduler, consistency implementation, storage or physical failure domains.

#### Results

| Check | Result | Evidence / boundary |
| --- | --- | --- |
| Actual deployed version and private MariaDB connection | PASS | Independent SSH release, exact tracked-runtime digest and read-only effective-configuration/database checks above. |
| Coverage of the private database, not website files | PASS | Database-backup image inspected; operator explicitly associated it with the actual private database. Account-specific association remains operator-attested. |
| Recurring frequency and retention | PASS | Provider policy: every 24 hours and at most 14 days; daily restore-point history corroborates recurrence. Current database is below the 2 GB weekly exception. |
| Recent successful provider backup | PASS | Available October 12 05:01:55 restore point in supplied panel evidence; timezone and underlying backup job logs not independently verified. |
| Restoration procedure and required access | PASS | Authenticated hosting-panel access authorized for the relevant service/database; Restore action or separate database creation from a selected restore point. Public provider procedures independently checked. Actual restoration not executed. |
| Provider restore, checksum, schema/integrity and application usability | NOT VERIFIED | No provider backup was downloaded or restored. Remaining step: restore a selected provider backup into a new non-public disposable database and perform read-only schema, aggregate-integrity and application checks with schedules/integrations disabled, then remove only those disposable resources. Not a closure prerequisite for the authorized provider route. |
| Provider storage location / independence from production-host failure | NOT VERIFIED | Obtain provider confirmation of failure-domain separation and disaster-recovery/access guarantees before claiming protection against loss of the host or provider account. No same-host or off-host claim is made for provider backups. |
| Existing application scheduler and manual backup preserved | PASS | One active cron entry, Laravel `schedule:run`; zero backup-like cron entries. Existing September 28 manual dump remains 1,198,685 bytes, `0600`, in a `0700` directory, with no adjacent checksum file. No host changes made. |
| REL-01 remains verified; intake remains disabled | PASS | Prior authenticated workflow closure preserved; fresh read-only host check confirms one assigned operator and disabled intake. The browser journey was not rerun. |
| New application-managed backup script/scheduler/failure/retention tests | NOT APPLICABLE | Adequate recurring provider protection established; no script, auth file, cron entry, dump execution or disposable restore resources were added. |

#### Restoration and limitations

Use the [repeatable private-database backup runbook](../../runbooks/phase5-release.md#private-database-backups-and-health-checks-rel-03). Hosting-panel authentication and authorization for the relevant service/database are required; own-file imports additionally require authorized database-interface access, with connection values retained privately. Prefer **Create database from backup** into a new isolated target; **Restore** on the live database replaces its state and needs separate approval. Do not delete the original database: the provider documents that a deleted database name cannot be reused. Database restoration does not restore source files, environment or storage. Provider processing times, consistency internals and recovery SLA were not verified.

The historical September 28 dump's isolated restore proves only that dump's restorability; it is not a test of these provider backups. The existing same-host manual dump is not protection against loss of that host. Provider failure-domain separation is unknown. Daily protection can lose changes since the last restore point; 14-day availability is not long-term archival.

No application behavior, dependencies, migrations, release, existing cron, production `.env` or `storage/` changed. REL-04 and other findings remain outside scope. No commit, push or publication performed.

**Closure decision:** recurring provider protection meets REL-03's documented provider route, with provenance and untested restoration/failure-domain boundaries explicit. REL-01 remains closed. Current verdict is **GO for documented personal use**, with intake disabled and all other accepted limitations retained; this is not a feasibility/calibration GO.

## REL-04 — Stable private configuration and storage

### Closure entry — 2026-10-13: copy-first shared-layout relocation

**Original finding:** [REL-04 in Audit 04](../04-reliability-dependability.md#confirmed-defects-by-severity). **Current status: CLOSED / VERIFIED.**

**Deployed version unchanged:** `v1.0.0`, `a00803b5947b7f52e18537e46edab86a949331da`. **Final independent host verification:** `2026-10-13T19:42:51Z`.

#### Verified layout and actions

The initial worktree was clean. Authorized SSH inspection confirmed that the historical coupling still existed; relocation was not based solely on the audit. Sanitized layout:

```text
Unchanged active code:
  ~/apps/fotp -> fotp-private-releases/<deployed-sha>
Before, in that active release:
  .env    -> ../../fotp-before-v1-<previous-sha>/.env
  storage -> ../../fotp-before-v1-<previous-sha>/storage
After, in that same active release:
  .env    -> ~/apps/fotp-private-shared/.env
  storage -> ~/apps/fotp-private-shared/storage
Recovery, not current runtime:
  ~/apps/fotp-before-v1-<previous-sha>/{.env,storage}
  ~/fotp-rel04-recovery-<date>/
```

The after-layout notation abbreviates absolute symlink targets; it does not imply literal tilde expansion inside a link. The dedicated shared directory did not exist before this task. It is outside public webroots and release/rollback directories, separate from staging's existing shared layout.

- Created a `0700` shared directory and a separate `0700` host-only recovery directory. Recorded original link targets, ownership/permissions, maintenance state, exact cron contents and the two private loopback-server processes privately. Copied the environment and complete storage tree first, preserving ownership, contents and existing access modes; verified the initial copy without printing contents.
- The environment and private profile remain `0600`; the private profile's enclosing directory remains `0700`. Existing storage directory permissions were preserved, with the shared parent restricting traversal. No blanket permissive permissions were introduced.
- Inspected effective storage, logging, sessions, file-cache/lock paths, maintenance, filesystems, compiled views and the profile. The profile setting already used the active application alias. Laravel's uncached `view.compiled` resolved physically into the old tree before cutover and into shared storage afterwards. No environment setting required editing; no configuration cache existed. No internal storage symlinks or public storage link existed. No cache-clear operation was necessary; sessions, database caches, locks and application data were not cleared.
- **Maintenance window:** `2026-10-13T19:40:14Z` to `2026-10-13T19:41:16Z`, approximately **62 seconds** including control restoration. Enabled prerendered application maintenance without a bypass. Verified HTML and JSON requests returned 503. HTML was stopped before normal bootstrap; JSON was stopped by global maintenance middleware before session/controller handling. Maintenance alone was not assumed to drain existing requests or stop non-HTTP writers.
- Temporarily commented only this application's original scheduler entry and suspended only the two verified private loopback-server processes. No queue worker was found in the authorized account. With those controls applied, storage file-content digests were stable across 60 seconds and the application's database account had no other active, non-sleeping connections. Then performed checksum-based final synchronization to shared storage and the recovery copy, with no destructive synchronization, and verified equality before cutover.
- Replaced the active release's two links with same-directory atomic renames while maintenance remained effective in both storage copies. Verified boot/configuration/profile/access before bringing the application live, then verified HTTPS checks. Restored the exact original cron contents and resumed the same loopback-server processes; their listener returned 200 and no open descriptor into old storage was found. Retained old storage remained unchanged during subsequent HTTP checks.
- No application code, key, database connection, private mode, debug/intake flag or unrelated setting changed. No migration, dependency update, release, fixture, evaluation, enrichment, feedback, provider-backup change, commit, push or publication occurred.

#### Individual verification results

| Check | Result | Evidence / boundary |
| --- | --- | --- |
| Actual production target and unchanged deployed version | PASS | Same active release; before/after digest of the accepted tracked runtime inventory matched `25d7ad7ca54223c33c349d6c430ff61d5b3af9a458a751acdc7d29962283ba03`. Not a fresh full dependency/asset audit. |
| Live environment/storage independent of retained rollback | PASS | Both active links resolve into the new dedicated private shared directory; old copies are retained but not live targets. |
| Complete copy, stable contents and required directories | PASS | Initial copy and quiesced final checksum comparison passed; recovery copy retained. Required log, view, file-cache, session and private-profile directories exist. |
| Private settings, key and actual database selection unchanged | PASS | Protected before/after configuration and environment fingerprints match, allowing only the expected resolved compiled-view path change. `SELECT DATABASE()` matches the unchanged private connection; production, debug off, private mode and disabled intake independently checked. |
| Profile load and storage read/write access | PASS | Application loader succeeds without exposing the definition; CLI runtime created/read/removed temporary access probes in required directories. Environment/profile modes and ownership preserved; live web boot succeeds through the protected shared parent. |
| Effective runtime paths and storage links | PASS | Recursive effective-config scan contains no rollback-directory reference; compiled-view path resolves to shared storage. No internal storage symlinks, public storage link, compiled-view rollback references or old-storage descriptors in the two loopback processes found. |
| Controlled interruption and write-settlement observations | PASS | HTML/JSON 503 barriers, application-only scheduler/process controls, stable 60-second storage digests and no other active database connections before final synchronization. This is observed settlement, not provider-level request tracing. |
| HTTPS login page | PASS | 200 before/after; trusted browser reload displays the sign-in form after cutover. |
| Private-mode demo denial | PASS | HTTPS `/demo` returns 404. |
| Unauthenticated protected JSON | PASS | HTTPS `/review/v1/opportunities` returns 401 with JSON content type, without an authenticated cookie. |
| Authenticated read-only REL-01 queue/detail check | NOT VERIFIED | The available trusted browser was signed out. No credentials requested, password reset, session fabricated or new fixture created. Optional follow-up: operator signs in directly and opens the retained REL-01 synthetic record without evaluating/saving it. Prior REL-01 authenticated closure remains accepted. |
| Normal service and original scheduler restored | PASS | Maintenance markers absent; exact original cron compared after restoration; original loopback processes resumed and login listener returns 200. Stage target unchanged. |
| Mailbox intake remains disabled | PASS | Effective flag verified repeatedly; no intake/connectivity operation invoked. |
| Rollback preparation | PASS | Protected original state, full recovery copies and a storage-preserving link-restoration procedure prepared before cutover. |
| Rollback execution / rehearsal | NOT VERIFIED | Not exercised: essential cutover checks passed. Preparation is not proof of a rehearsed rollback. |
| Provider web-worker internals / host-loss recovery | NOT VERIFIED | Account-scoped process inspection and application HTTP/storage/database checks do not expose provider worker internals or establish off-host recovery. Same-host recovery copies are not independent backups. REL-03's existing provider-database coverage is unchanged. |

#### Recovery disposition and closure

Retain both the original rollback installation's environment/storage and the protected task recovery directory as **recovery snapshots, not current runtime state**. The task recovery storage includes the quiesced maintenance markers; apply the recorded prior maintenance state during recovery rather than serving that snapshot blindly. No retained release was deleted or archived.

Follow the [current shared-layout and recovery runbook](../../runbooks/phase5-release.md#private-shared-configuration-and-storage-rel-04). Future code deployments and rollbacks must link to the current shared environment/storage, including when reusing the retained older installation; never reactivate its stale regular-file/directory copies. If layout recovery becomes necessary, quiesce writers again, preserve the latest shared state and reconcile post-cutover additions, updates and deletions before restoring original links. Do not overwrite newer state from an older snapshot.

**Closure decision:** relocation and essential operational checks passed. The unavailable optional authenticated read-only repeat and unexercised rollback are explicitly not claimed. REL-01/REL-03 remain closed, Phases 1–5 remain complete, and **GO for documented personal use** is unchanged with intake disabled and all unrelated findings/accepted limitations retained. No feasibility/calibration GO is implied.
