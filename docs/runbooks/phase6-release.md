# Phase 6.4 owner delivery and bounded release acceptance

**2026-10-31 new execution scope APPROVED:** after independent offline verification, the owner approved a separate schema-v2 fix PR (preserving open checkpoint PR #81), normal protected integration, independent exact-main CI/four-file artifact verification, protected public-demo backup and inactive installation, and real loopback/SSH-tunnel acceptance with bounded dedicated scheduling. Existing 19 migrations/history and the already populated 20-message corpus must be retained; no blind migrations, reseeding or APPEND.

The new allowance is **one run**, at most 20 items/two existing-policy attempts per item (**40 counts + 40 inferences maximum**), **USD 0.075680 maximum initial modeled-inference reservation**, original **30-minute admission deadline**, and **45-minute helper window**. Existing concurrency/rate limits and USD 1/day inference-only ledger remain; count fees/endpoint retention remain unknown under the existing accepted-risk decision. Recheck all UTC liabilities before admission and stop dispatch on uncertainty; no whole-run repeat.

The owner explicitly selected **all 20 approved inputs selected and 20/20 valid successful results**, no unresolved uncertainty or obvious rubric violations, as the new recommendation acceptance gate. All isolation, real PEEK preservation, report consistency, accounting, browser and recovery criteria also apply. At most **one new finalized report email** to the existing protected sole recipient is authorized through the implemented command, with duplicate-state verification and **no old-report resend or mail retry**. Record receipt only after explicit owner confirmation. Only after all gates and owner receipt pass may cache-aware disable/data-preserving HTTPS-verified rollback lead to opt-in activation and unused v1.0.2 publication from the exact accepted archive. Failure leaves/restores disabled v1.0.1; permission denials are never bypassed.

**Independent local checks in this continuation:** 50 provider/counting tests / 1,324 assertions PASS with unreachable offline database configuration and controlled HTTP; project PHPStan, dirty Pint and whitespace checks PASS. Installed Laravel 13.36.0/Boost 2.10.3 verified; no dependency change/reinstallation. Current branch/head, all 14 modified source/evidence files, open PR #81 and protected main `19c92a463b99b8eee21469ad290399875ac08102` were reverified. No new full-suite/browser/coverage CI, host mutation, paid operation, email or release is claimed by this entry.

**2026-10-31 latest outcome:** the owner separately approved one one-item/one-attempt diagnostic, then a narrowly scoped **offline schema-contract fix**. The diagnostic failed exact-evidence validation for a bare field name; its modeled inference settled USD **0.000291**, with one counting fee still unknown. The local v2 schema now declares the existing allowed evidence and missing-information identifiers; focused tests/static analysis pass, but these changes are **not committed, integrated, deployed or live accepted**. Public v1.0.1 and all disabled switches remain unchanged. Further paid work, email and release are not authorized by the offline-fix decision. See [diagnostic and offline correction](#one-item-diagnostic-and-offline-schema-correction---2026-10-31).

**2026-10-30 latest outcome: offline implementation and protected integration COMPLETE; the one bounded live acceptance FAILED.** Normal artifact retrieval succeeded on resumption without an authorization bypass. The exact candidate selected 20 authored alerts, but none produced an accepted recommendation after the permitted 40 attempts. One failure-report email was SMTP-accepted; the owner reports nonreceipt. The public site was restored to v1.0.1 after an HTTPS-verified, data-preserving disabled-code rehearsal. Feature, counting, scheduler and owner delivery are disabled; v1.0.2 is not published. See [actual resumed execution](#resumed-bounded-execution---2026-10-30). Phases 1-5, previous releases, historical audits and the unsuccessful product-feasibility result are unchanged.

## Baseline and candidate

- Repository baseline: `eaa26d1f8b81ed3fcd6d2f2fca81ac7339677c15`, including merged 6.3 PR #78. Existing 6.3 CI and the guarded shared-original comparison are recorded in [readiness](phase6-readiness.md#slice-63-final-integration-outcome---2026-10-30).
- Review branch: `feat/phase64-owner-delivery`. The exact frozen PR-head SHA and passing protected checks must accompany the grouped approval. Any code change invalidates that candidate's checks.
- [PR #80](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/80) preserves sequential implementation `7d4de79` and documentation `65f2d5c` / `b15f544`. Following grouped owner approval, normal protected squash merge completed at **2026-10-30T20:02:37Z**, yielding exact main candidate **`19c92a463b99b8eee21469ad290399875ac08102`**. Its [own post-merge CI 41120132226](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41120132226) succeeded. No protections were bypassed.
- Normal protected integration, never bypass: Quality, Tests / MariaDB 11.4 and Secret scan at the final PR head. After authorized merge, obtain the resulting **exact main SHA** and passing push CI. Download only its `release-candidate-<sha>-<attempt>` artifact, strictly verify the four files/checksums/inventory, and record its archive digest. PR-head results are not release evidence for a different main revision.
- Proposed unused version: **v1.0.2**, following the existing publication validator's `v1.0.<patch>` convention. No versioning-policy expansion, tag overwrite or publication is authorized by this document. The exact main archive, not a local rebuild or the old v1.0.1 archive, must pass acceptance.

## Implemented owner delivery

```sh
/usr/bin/php8.4 artisan demo-ai:deliver-owner <existing-run-id> --no-interaction
/usr/bin/php8.4 artisan demo-ai:deliver-owner <existing-run-id> --recover --no-interaction
/usr/bin/php8.4 artisan demo-ai:deliver-owner <existing-run-id> --receipt=<opaque-evidence-reference> --no-interaction
```

Only a protected operator shell can invoke this command; there is no HTTP sending route, recipient argument, administration UI or mail queue job. A queue redelivery cannot dispatch mail. Evaluation jobs and inference accounting are untouched.

Protected `DEMO_AI_OWNER_DELIVERY_ENABLED` (default false), `DEMO_AI_OWNER_RECIPIENT`, `DEMO_AI_OWNER_RECIPIENT_SHA256` (exact address allowlist digest), `DEMO_AI_OWNER_RECIPIENT_VERSION` and `DEMO_AI_OWNER_VERIFICATION_REFERENCE` must all be supplied. The reference identifies the existing explicit owner-control/sole-recipient attestation, not a fabricated machine verification. Configure through the host-only environment; never put the actual address, credentials or allowlist digest in chat, repository evidence or logs.

The command requires the guarded demo MariaDB and server-configured demo workspace. It refuses missing/foreign/private, unfinished, purged/expired or unavailable stored reports and non-public profile snapshots. It renders the existing immutable `demo-report-v1` using the identical `review.ai-report` Blade view as the screen/download, sends that HTML and attaches exactly the same HTML bytes under a server-owned ULID filename. It never reconstructs results from current inputs, runs intake or calls AI.

The existing Laravel mail infrastructure builds an isolated SMTP mailer from protected SMTP host/credentials, valid From and port 465/587. TLS and certificate validation are required, timeout ten seconds; no log, array, failover, round-robin or global recipient override is selected in production. Controlled tests substitute only the transport. This is intentionally synchronous so the operator records the immediate outcome and no separately running mail worker can retry it.

### Durable state and recovery

The additive `demo_ai_deliveries` table retains report hash, rendered HTML hash, HMAC recipient/configuration identity, configuration version, hashed verification/receipt references and timestamps; no address, HTML content, credentials or raw exception. A run-row lock and committed atomic `prepared -> sending` transition precede transport access. Primary uniqueness on **run ID** is deliberately stronger than `(run, snapshot, recipient version)`: rotating configuration, changing code or redelivering a trigger cannot send a second copy of this report.

| State | Meaning / operator action |
| --- | --- |
| `prepared` | Durable intent, not external acceptance. Repeated invocation does not send. Interrupted preparation may be classified `failed` with `--recover`. |
| `sending` | Transport may have begun; never resend. After 150 seconds, `--recover` can classify process loss as `uncertain`. Quiesce the original process before investigation. |
| `accepted` | Laravel transport returned confirmed acceptance; **not owner receipt**. Repeated commands return existing state without sending. |
| `failed` | Known local pre-send failure/interrupted preparation; no automatic retry or state reset. Further mail requires a separate reviewed operator decision, outside this one-email allowance. |
| `uncertain` | Exception/null result/process loss can hide SMTP acceptance. Retain uncertainty; **no automatic or blind resend**. Resolve from authorized transport/owner evidence, not by testing another send. |

`--receipt` only records a separate immutable timestamp/reference hash after `accepted` or `uncertain`; it does not send or rewrite SMTP state. It is **OPERATOR-REPORTED** receipt, not agent-inspected inbox evidence. References must be opaque identifiers, never addresses or transport dumps. No automatic IMAP inspection of a personal inbox.

Mail failure cannot release inference liabilities, change results, extend expiry or restart evaluation. Seven-day cleanup removes report content but retains delivery/accounting identity. A committed sending row plus uniqueness is **not exactly-once external delivery proof**. If the process disappears after SMTP acceptance and before persistence, its durable outcome remains uncertain.

## New offline evidence

Controlled Symfony array/ambiguous transports exercise the real Laravel HTML/MIME path. No mailbox, count, inference or external email calls occur in CI. Tests cover stored HTML/attachment equality and escaping; recipient configuration/overrides; missing, expired, purged, foreign/private and unavailable reports; repeated commands/configuration rotation; real four-process MariaDB contention; known preparation failure; SMTP ambiguity and stale-process recovery; separate receipt; unchanged AI tables/provider calls/queue; and retention of unresolved inference/delivery liabilities.

Local checks already executed during implementation:

| Check | Result / scope |
| --- | --- |
| Guarded disposable MariaDB | Dedicated task-labelled MariaDB 11.4.13, loopback 3307, exact `_test` database and hostname verified; repository safety guard passed |
| Focused owner-delivery suite | 21 tests / 132 assertions passed before the final metadata-only addition; final-head CI must revalidate all changes |
| Final full PHPUnit with PCOV | **320 tests / 2,555 assertions**, including final metadata, null-transport, disable-recheck and outer-transaction cases; coverage **90.44% overall / 93.64% parser-domain / 96.90% triage**, passing existing thresholds |
| Full installed Chromium suite | **24 passed**, including unchanged private/seeded journeys; controlled application adapters only |
| PHPStan / Pint | Passed on the final implementation, zero static-analysis errors |
| Composer validate / locked audit / npm high-severity audit | Passed, no advisories reported |
| Production Vite build | Passed with installed packages; not represented as a fresh locked install |
| Release / sheet-sync contracts | 6 / 25 passed |

Fresh locked CI (including audits/secret scan/inventory) at the exact final PR head is required before execution approval; its durable run/check records accompany the candidate rather than substituting older results. The local browser/build used existing installed packages, not a fresh locked install. Initial failed test attempts (output matcher/expected wording and incomplete test-case match) were corrected, not suppressed or described as passes. The complete implementation, tests, migration and documentation diff was reviewed before proposing execution.

### First fresh locked draft-candidate CI - 2026-10-30

VERIFIED: [CI 41118856780](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41118856780) succeeded for exact PR head `65f2d5c27eec599e65059e01ed8028ce247e50af`. Quality, Tests / MariaDB 11.4 and Secret scan all passed. Logs explicitly show fresh `composer install` and `npm ci`, successful production build, locked Composer/npm audits without reported vulnerabilities, **320 PHPUnit tests / 2,555 assertions**, coverage **90.44% / 93.64% / 96.90%**, **24 browser tests**, release/sync contracts, verified Syft inventory and candidate preparation/validation. This is new **6.4 offline** evidence, not live inference/email acceptance or post-merge release-archive evidence. This documentation-closure update must also pass its own final-head checks; the durable PR records identify that revision/run.

Task infrastructure was cleaned after validation: exact container `116c405882df284a7047742ab1142e51b7d3dc8b8e38509aee9344260fdf9976`, task label, loopback binding and SQL hostname/database were reverified before removing only that disposable container/anonymous volume. Ports 3307, 8011, 8012 and 8013 were confirmed closed. No unrelated container, deployed database or host process was changed.

### Final PR-head and post-merge verification

VERIFIED: final PR head **`b15f54425ce58e9989e4d04a866e34861e333357`** passed its own [CI 41119464142](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41119464142), then merged main candidate **`19c92a463b99b8eee21469ad290399875ac08102`** independently passed [CI 41120132226](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41120132226). Both completed Quality, Tests / MariaDB 11.4 and Secret scan successfully; fresh locked installations/build, audits, **320 tests / 2,555 assertions**, **24 browser tests**, **90.44% / 93.64% / 96.90%** coverage and inventory/candidate validation passed. Logs were inspected, not inferred from the previous head. Exact archive download/checksum/host acceptance remains **NOT VERIFIED**; a green CI artifact producer is not a downloaded/deployed artifact.

## Protected read-only feasibility

VERIFIED on 2026-10-30 through the existing `fotp-public-demo` SSH alias, with strict known-host checking and no host changes:

- Correct public URL `https://fotp.kontentwave.com`, demo review mode, dedicated `_demo` MariaDB, independent `fotp-public-demo-session`, shared environment mode 0600 and parent mode 0700. Private intake disabled; configuration uncached.
- Current link `~/apps/fotp-demo/current -> releases/7806994bfe639ffc3185f6d3dd742511d36b9870`; webroot points to this account's `current/public`. This matches the recorded v1.0.1 deployment, not private or retired stage.
- Candidate target: `~/apps/fotp-demo/releases/<exact-main-sha>`, `.env -> ~/apps/fotp-demo/shared/.env`, `storage -> ~/apps/fotp-demo/shared/storage`. No private key/session/database/storage sharing.
- Host default `php` is **8.5.12**; explicit **`/usr/bin/php8.4` is 8.4.27**, with pcntl. Use that absolute binary in every worker, Artisan and scheduler command. `timeout`/`flock` available. No existing crontab lines; no schedule installed.
- Read-only SQL transaction: 16 existing migrations, no `demo_ai_*` tables; 2 workspaces, 2 users, 28 opportunities, 3 reviews, 2 enrichments, zero jobs/failed jobs. No data rows read out or changed.
- Existing protected staging directory `~/.fotp-phase61-operator` is 0700; all four populated owner-held files are 0600. Recipient syntax/separation and provider-key presence verified without disclosure. Existing owner-control, project/billing/data-setting attestations remain **OPERATOR-REPORTED**; not repeated as machine-verified facts.
- **SMTP is not ready:** current default is array, localhost/2525, no SMTP credentials. No SMTP connection or send attempted. Dedicated demo folder is not configured. These require protected operator input and explicit corpus-population authorization before live work.
- No isolated active acceptance installation exists. Port availability, hard-stop execution and integrated scheduler behavior must be established during the authorized setup; a CLI/symlink check alone is not web-version evidence.

Official model and pricing pages were re-read on 2026-10-30: `gpt-6-luna`, Responses/structured outputs/reasoning none, Standard USD 0.10 input / 0.01 cached / 0.125 default 30-minute writes / 0.50 output per million still match ADR-010. Recheck immediately before use and stop for a revised decision on a material change. Unknown counting fees/endpoint retention remain accepted but **NOT VERIFIED**; no support inquiry, free-counting assertion or guaranteed prepaid hard cap.

[Official Websupport client configuration](https://www.websupport.sk/podpora/kb/nastavenie-posty-v-outlooku-2019/) was also checked: outgoing SMTP uses port **465, SSL/TLS**, with the exact server supplied in the owner's control panel. Do not invent the outgoing hostname from the known IMAP hostname, disable provider protections or assume SMTP authentication from historical IMAP success. The grouped decision can authorize reuse of the protected demo-mailbox credentials/From if the owner chooses; otherwise stage separate SMTP settings in a 0600 `~/.fotp-phase61-operator/smtp.env` file, never chat. No authentication or send was performed.

## Approved grouped execution decision and remaining gate

**APPROVED by the owner in this session:** full bounded plan, normal protected merge, exact resulting main CI/archive verification, public-demo-only backup/migrations/loopback staging, authorized corpus APPEND, one bounded acceptance run, at most one owner report email, disable/code rollback, and conditional opt-in/v1.0.2 publication only after successful acceptance and owner receipt. Do not request these permissions again unless the scope/material assumptions change. No action may bypass a failed audit/check, change code/model/rates/caps, use private/retired targets, or repeat the run to improve results.

Owner inputs and decisions:

1. Full sequence and unused **v1.0.2** conditionally approved, not published.
2. Reuse of the protected **dedicated project demo-mailbox** credentials and mailbox identity as From approved. The owner supplied **`smtp.m1.websupport.sk:465`** (OPERATOR-REPORTED; no SMTP connection/authentication/send yet). The initially reported ready `smtp.env` file was actually absent; the subsequent explicit hostname/port resolves that input without requesting any secret in chat. Existing protected recipient remains the sole approved destination.
3. Creation/use of **`FOTP-Authored-Demo-Phase6`** and IMAP APPEND of exactly the 20 approved RFC822 files authorized, **not executed**. No fabricated marketplace sends, real alerts or unrelated mailbox edits.
4. Protected backup/recovery preparation, bounded loopback staging/scheduler and short public code-switch/rollback window authorized, **not executed**. Read-only follow-up confirmed `mariadb-dump`, Composer and the staging port available.

### Bounded operations and budget

- Exactly **one acceptance run**, at most 20 approved unique fictional sources, original 30-minute deadline. No repeat whole run after failure/uncertainty.
- At most one existing classified retry/item (two attempts maximum), only for existing approved classifications. Count/inference share concurrency one, rolling 30 operations/minute and >=2-second spacing; no separate counting experiments. At most 40 inference attempts and their request-bound counts, not a new unconditional retry allowance.
- Full-run inference reservation **75,680 micro-USD = USD 0.075680** (both attempts, margin included). Before admission, inspect all-day unresolved reservations plus current UTC settled ledger. There are presently no feature tables, so expected fresh capacity is USD 1.000000, leaving **USD 0.924320** after this reservation, **conditional on the new ledger check**; no observed live ledger balance is claimed. Preserve uncertainty across days. Stop additional paid dispatch on uncertain accounting.
- USD 1.00/day bounds modeled inference only. Counting fees remain independently unknown/null and endpoint retention unverified. Report measured inference usage/cost from actual attempts separately; prepaid balance/no recharge is not a hard cap.
- At most **one external report email**, no retry after SMTP ambiguity. Duplicate triggers must return stored state and produce no additional send. Owner receipt requires authorized evidence or explicit owner report.
- One bounded setup/acceptance window (maximum 45 minutes for helpers; run deadline remains 30 minutes), with automatic no-work deadline for the temporary scheduler. No permanent helper/cron until explicitly accepted opt-in. Cleanup removes only this task's helper files/owned cron entry; preserve mailbox sources, successful results, historical review data and sent/uncertain liabilities.

### Approved-sequence checklist (checkpoint plan; actual execution below)

1. Freeze PR head, verify protected checks/fresh locked install and review complete diff. After authorized normal merge, wait for **that main revision's** green CI and verify/download its four-file archive/inventory. Record full SHA, run/attempt, archive SHA-256 and UTC times. Any source change restarts candidate validation, not paid acceptance.
2. Reverify SSH account/public hostname/current link/demo DB/storage/session/key isolation and disabled private intake. Back up demo DB consistently plus shared environment/storage/current link/crontab into a new 0700 recovery directory with 0600 files outside webroot. Check completion/hash/table inventory; verify readable recovery material and rehearse non-destructive recovery on an explicitly isolated target if available. Provider-backup history is not new restore proof; do not reset live data.
3. Extract the exact archive into the inactive SHA release; install **locked production Composer dependencies** under PHP 8.4 and verify platform requirements. Do not rebuild compiled assets. Link only public-demo shared config/storage and verify paths.
4. Apply only these three pending additive migrations to the verified demo DB, preserving the existing 16 and all history:
   - `2026_10_28_142713_create_demo_ai_state_tables`
   - `2026_10_29_211053_add_report_snapshot_to_demo_ai_runs_table`
   - `2026_10_30_193616_create_demo_ai_deliveries_table`
   Never run fresh/seed/down migrations. Delivery table restricts deletion of its run; all feature identity/ledger rows survive code rollback.
5. Configure protected synthetic IMAP, selected provider and exact accepted-risk reference `ADR-010:2026-10-29:accepted-counting-risk` with empty policy-evidence hash, owner allowlist/version/attestation and suitable TLS SMTP. Verify no private override. Stage remains inactive publicly: **old public v1.0.1 has no paid routes**.
6. Bind the inactive candidate web server **only to `127.0.0.1:8014`**, after verifying port availability, and access over an authenticated SSH tunnel. Do not proxy/expose its port publicly. Use the same candidate application/real bindings, not the controlled browser-test router. Verify server responsiveness and exact served compiled asset hashes. Keep old public root unchanged throughout live acceptance; general visitor admission remains unavailable.
7. Under the grouped corpus authorization, import/APPEND only the approved 20 messages to the dedicated folder; compare exact normalized hashes/IDs and preserve pre-existing unrelated contents. Record approved-source selection, bounded UID snapshot/UIDVALIDITY, duplicates, flags/content before/after real certificate-validated EXAMINE/BODY.PEEK.
8. Run the candidate's real `schedule:run` from an owned temporary minute-crontab entry with `/usr/bin/php8.4`, fixed deadline and suppressed cron mail; retain baseline for exact restoration. Its configured worker remains dedicated `demo-ai`, database retry_after 150, `--stop-when-empty --max-time=40 --timeout=45 --sleep=1 --tries=1`, outer timeout 100 + 5-second kill grace, overlap 180 seconds. Do not drain default/private queues.
9. Through the tunneled real web session, explicitly prepare/admit **one** run, observe stored progress, actual request-bound count/inference provenance and validated outputs. Preserve partial/honest failures; stop on uncertainty, never rerun to clean up. Record actual inference token/cost settlement and unknown count fees separately. Review recommendations against public rubric for obvious hard-constraint/unsupported-evidence violations; expected bands are aids, not general accuracy proof.
10. Compare finalized stored report and actual downloaded HTML; use operator command to send that report once. Repeat the trigger to verify no extra external send. Inspect sanitized delivery state; obtain explicit owner receipt or authorized transport/owner evidence. SMTP acceptance alone leaves receipt pending. Disable new admissions/paid dispatch if anything is uncertain.
11. Quiesce scheduling/new work, wait for bounded workers/requests or classify process loss as uncertain; run recovery without releasing unresolved liabilities. Disable **feature, counting, scheduler and owner delivery** in host config, then clear each release's cached config with explicit PHP 8.4 (rebuild only if deliberately using caching). Confirm seeded demo and no admission/provider dispatch.
12. With switches disabled, atomically switch `current` to the candidate; verify **actual HTTPS-served** compiled asset bytes/HTML against the archive, not CLI/current alone. Allow for known shared-host OPcache delay and fail if old assets remain served. Exercise seeded queue/feedback/session/foreign/private isolation, then roll code/assets back to `releases/7806994bfe639ffc3185f6d3dd742511d36b9870` against the **same additive schema/config/storage**, verify served bytes and unchanged history/liabilities, restore the candidate and recheck. Do not down-migrate/drop/delete feature rows.
13. Only if every applicable acceptance item passes (including owner receipt), deliberately enable feature/counting/scheduler under the approved opt-in; keep private intake disabled and owner delivery disabled except the bounded command. Install only the accepted minute scheduler with bounded worker/recovery. Reverify configuration/cache behavior and public served version. If acceptance fails, leave switches disabled, stop owned helpers and keep seeded demo/previous safe code.
14. Publish only accepted exact main archive through the existing [release workflow](phase5-release.md#publication-and-partial-failure), with approved unused v1.0.2, exact CI run, archive digest and post-restoration smoke timestamp. Verify tag, all four asset digests, actual web-served code and previous release immutability. Partial release failure requires inspection, not automatic retry/tag replacement.

## Actual live/release outcome

**Historical checkpoint at `4cac97bf0f49acb86607ab6c12d53d02917346a9`, before this continuation.** The following unconsumed-allowance and authorization-blocker statements describe that checkpoint, not the subsequent execution below.

**NOT VERIFIED / NOT EXECUTED:** corpus population/retrieval, paid count/inference, live worker/scheduler, SMTP acceptance, owner receipt, migration/backup/restore, host changes, disable/code rollback rehearsal, activation and release publication. Newly incurred inference/email cost is **USD 0** because none was executed; unknown historical counting charges remain unverified. No new release or owner receipt is claimed. Phase 6 cannot be marked complete until these applicable criteria have actual evidence.

**Concrete blocker:** the tool authorization layer refused the exact-main candidate download/preparation call and reported that it could not obtain the required runtime permission. The operation was not retried through another path/tool/location. Read-only GitHub inspection independently verified the merged candidate's green CI afterward. Owner approval remains recorded; this is a runtime execution-permission limitation, not missing owner consent or a reason to reopen counting policy. Resume at exact candidate artifact verification only after that execution gate is available, preserving the existing one-run/one-email allowance. No paid allowance was consumed and no host/helper/scheduler cleanup is outstanding.

## Resumed bounded execution - 2026-10-30

### Frozen candidate and runtime gate

VERIFIED: the worktree initially was clean on `docs/phase64-live-gate-checkpoint`, exactly `4cac97bf0f49acb86607ab6c12d53d02917346a9`. [PR #81](https://github.com/KontentWave/freelance-opportunity-triage-platform/pull/81) was OPEN, unmerged at that head. No checkout, merge, rebuild, dependency-manifest change, commit or push substituted another source candidate.

Read-only GitHub metadata reconfirmed push-to-main [CI 41120132226](https://github.com/KontentWave/freelance-opportunity-triage-platform/actions/runs/41120132226), exact **`19c92a463b99b8eee21469ad290399875ac08102`**, latest completed **attempt 1**, success, with Quality, Tests / MariaDB 11.4 and Secret scan all successful. The matching artifact `release-candidate-19c92a463b99b8eee21469ad290399875ac08102-1`, ID `13006989577`, was unexpired until `2026-11-29T20:06:16Z`. Its GitHub-reported ZIP digest was `ea48ee875acabd7c5f575e3d294e26ecc937fd441ef814015abf5f757610d033`; this is distinct from the independently checked application archive.

The first normal `gh run download` invocation in this continuation succeeded through the supported execution interface. No current denial was routed around or blanket permission granted. This establishes that retrieval was permitted now, **not the cause of the earlier runtime denial**. Existing owner authorization was not requested again.

VERIFIED locally and after protected SSH transfer: exactly four regular files, strict `SHA256SUMS`, exact manifest repository/SHA/run/attempt, safe archive inventory and complete dependency inventory against both archived lockfiles. The repository's `validateFiles` validator passed. No branch checkout, old release archive or local build was used.

| File | Independently verified SHA-256 |
| --- | --- |
| `freelance-opportunity-triage-platform-19c92a463b99b8eee21469ad290399875ac08102.tar.gz` | `043303428b0582cef203b99b9490b0638d4db95da341daabb2872e3244578712` |
| `candidate.json` | `3499a48e084c5bfdc03a09abdd55e749d987217b39b3580a40f73b0b7564d552` |
| `sbom.cdx.json` | `c6d8c499154142aaf4d4febc17ae30fe4041d17582ca4e1b342e52632de6a383` |
| `SHA256SUMS` | `7bd5b0dae0aabe775844a8ef59bcd0536bd7919c3c9587f7e37021ca34d306c0` |

The 320 PHPUnit tests / 2,555 assertions, 24 browser tests and 90.44% / 93.64% / 96.90% coverage remain the **existing exact-revision CI evidence**, not a rerun in this continuation.

### Protected target, recovery and isolated execution

VERIFIED with `fotp-public-demo`, BatchMode and strict host-key checking: original v1.0.1 link/webroot, dedicated MariaDB `_demo`, demo mode, disabled private intake, independent shared environment/storage, valid application key and secure host-only public session configuration. Protected directory/file ownership, non-symlink regular files and 0700/0600 modes passed without disclosure. `/usr/bin/php8.4` was **8.4.27**. The first port probe could not use absent `ss`; a subsequent actual loopback bind and later `/proc/net/tcp` inspection established availability/isolation. An initial overly narrow `mysql`-connection-name check was corrected read-only to the existing supported `mariadb` driver; no target or database was changed to satisfy it.

At **20:18:47Z**, a new protected same-host recovery set held a complete consistent SQL dump, original environment, readable storage archive/inventory, original current link, absent crontab baseline, table inventory and historical row digests. File modes/checksums and dump completion passed; historical application rows remained unchanged during preparation. **Restore into an isolated database was NOT VERIFIED.** Readability is not restore proof; SQL/storage are not a cross-resource atomic snapshot, and same-host copies do not prove host-loss recovery or renew private REL-03 backup evidence.

The exact archive was installed inactive at `releases/19c92a463b99b8eee21469ad290399875ac08102`, with the existing public-demo shared environment/storage links. Locked production Composer installation and platform checks passed under explicit PHP 8.4; assets were not rebuilt. Rechecking the actual 16 applied migrations found exactly the three approved pending additive migrations. Only those ran, completing at **20:19:14Z** with **19 applied migrations**. No reset, seeder, historical deletion or down migration ran.

A protected, host-only runtime overlay supplied the approved dedicated source/SMTP identity and sole recipient. The shared public `.env` remained byte-for-byte unchanged and disabled. Only the isolated candidate processes received enabled feature/counting/scheduler values; owner delivery stayed disabled except for its bounded command. Loopback browser cookies were separately named; public HTTPS session settings were unchanged.

VERIFIED: real candidate served at **127.0.0.1:8014 only**, accessed through authenticated loopback-only SSH forwarding, with automatic 45-minute hard stop. Actual served CSS/JS matched archive bytes at **20:24:00Z**. The public seeded site remained old code during paid acceptance. No controlled browser-test router was used.

Exactly the approved **20 authored-demo-v2 RFC822 files** were APPENDed once to `FOTP-Authored-Demo-Phase6`; per-message durable intent/acceptance evidence prevents silent repetition. Real certificate-validated EXAMINE/BODY.PEEK established **UIDVALIDITY 1793117440, UIDNEXT 21**, 20 unique approved normalized hashes/IDs. The real application selected descending UIDs 20 through 1, with no duplicate/rejected source. Before/after raw content hashes and persistent flags matched at **20:32:54Z**; session-local `\Recent` was not misrepresented as a persistent flag. No genuine alerts, SMTP-fabricated marketplace sends, moves, deletion or unrelated mailbox changes were performed.

The temporary owned minute cron invoked the real `schedule:run`, dedicated database worker and recovery with the committed timeout/queue/overlap controls. An initial long-filename `crontab <file>` invocation failed before installation; its supported stdin interface succeeded with exact readback. This was an OS command failure, not the earlier runtime authorization denial. A fixed helper deadline and stop sentinel bounded further work. Default/private queues were not drained.

### One live run: actual failure and reconciled usage

Immediately before admission at **20:24:02Z**, the actual UTC ledger showed **0 settled / 0 outstanding reserved micro-USD**, no runs/attempts/deliveries and no accounting block. The measured remaining capacity after the planned **75,680 micro-USD** reservation was **924,320 micro-USD**. This was measured here, not inferred from the fresh-schema expectation.

The real browser admitted exactly one run, **`01m677qrdnpzan84tj7shz9jkv`**, at **20:24:05Z**, original deadline **20:54:05Z**. Replay of its original server-issued intent returned the same run without a second admission. Stored progress was observed from queued through running to **failed**, finalized **20:32:18Z**, expiring **2026-11-06T20:32:18Z**.

| Actual observation | Result |
| --- | --- |
| Selected / succeeded / failed | **20 / 0 / 20**; no accepted recommendation |
| Count / inference operations | **40 / 40**, one request-bound count per actual inference attempt |
| Attempts | **40**, maximum **2 per item**; only the existing classified retry |
| Classified failures | **22 `demo_ai.evidence_invalid`**, **17 `demo_ai.output_invalid`**, **1 `demo_ai.response_incomplete`** |
| Actual full-input counts | **1,081-1,122**, all below 8,000; current attempt provenance, not reused historical corpus measurements |
| Requested / returned model | `gpt-6-luna`; current official model/settings/rates rechecked unchanged before use |
| Shared operation authorization spacing / rolling minute | Minimum **2 seconds**, maximum **16 operations** in any rolling minute; sequential attempts, no overlapping durable attempt intervals |
| Actual usage | **120 ordinary input / 22,072 cache-read / 22,072 cache-write / 11,649 output tokens** |
| Settled modeled inference | **8,835 micro-USD = USD 0.008835**, per-attempt upward rounding |
| Inference uncertainty / remaining reservations | **0 / 0**; all 40 attempts known, accounting unblocked |
| Counting fees / endpoint retention | **NOT VERIFIED**; all 40 potentially charged counts retain null/unverified fees, separately from inference settlement |
| Queue after completion | **0 jobs / 0 failed jobs** |

The usage is actual application/provider-response reconciliation, not an independently inspected provider invoice. Unknown counting fees and any separate email charge are not claimed zero. The USD 1.00/day contract still covers modeled inference only. No counting-policy support inquiry, model/rate/cap change, fabricated evidence, paid replay or second whole run occurred.

The finalized failure report is honest: all items are unsuccessful, not invented SKIP recommendations. No recommendation-quality or hard-constraint accuracy pass can be claimed when none succeeded. The retained codes identify validation/incomplete-response failures, **not their exact raw-response root cause**; raw responses were deliberately not retained, and no new paid diagnostic request was made. Do not weaken the validators to make this candidate pass.

### Stored report, single owner send and nonreceipt

VERIFIED through the owning real browser session: on-screen report and actual HTML download endpoint returned identical bodies, with the expected safe filename. Finalized snapshot SHA-256 **`7565225013bd5acbd5ed40d639586d6677998b7be7be0084c1cd4dba3ad39453`**; rendered/delivered HTML SHA-256 **`6c886573cc857d77ad7ac3ed5bd2a918e205aa9a7390d9fd3e37c9474b34bd43`**. The stored report contains the failed outcomes and remains immutable through recovery and rollback.

At **20:33:23Z**, one invocation of `demo-ai:deliver-owner 01m677qrdnpzan84tj7shz9jkv` returned **accepted** using the approved protected source identity and `smtp.m1.websupport.sk:465` with required TLS/certificate verification. This verifies application-observed SMTP acceptance/authentication, not owner receipt. A duplicate invocation returned the same durable accepted state without another send; exactly one delivery row remains. AI attempts/results/accounting were not restarted by delivery.

**OPERATOR-REPORTED: owner has not received the report.** The owner clarified that the newly supplied address referred to the dedicated project source/SMTP sender, not the recipient. A host-only comparison VERIFIED that the already-used protected source identity exactly matched that clarification and that the protected sole recipient was distinct. No identity/address was changed or exposed. No positive `--receipt` command was run; `receipt_reported_at` remains null. Nonreceipt is retained separately from the durable accepted SMTP state. No resend, alternate destination or personal-inbox inspection was attempted.

### Disable, same-schema rollback and safe handoff

VERIFIED: owned cron removed with exact restoration of the originally absent crontab; bounded work quiesced, recovery run with feature/counting/scheduler/owner-delivery disabled. The staging SSH/server stop initially left two identifiable remote web processes; only those exact PIDs were terminated, then host/tunnel port closure was verified. The runtime overlay was explicitly disabled and retired into protected recovery; owned mailbox/scheduler helper files were removed. Final host ports **3307, 8011, 8012, 8013 and 8014** had no listeners. No permanent scheduler was installed.

Both releases' config caches were explicitly cleared with PHP 8.4, without flushing sessions, data caches, locks or historical data. With all switches disabled and no outstanding work, `current` briefly selected the candidate at **20:42:56Z**. After a bounded 130-second cache-convergence wait, **actual HTTPS HTML and exact candidate CSS/JS** passed at **20:45:16.516Z**. The existing public session, queue, three reviewed opportunities, retained feedback/enrichment, foreign-workspace 404 and disabled admission were checked. A no-origin authenticated API-context tokenless write returned **419**. An earlier same-origin browser-fetch proxy for this check reached validation (422); it was not falsely reported as a successful negative-CSRF test.

Code/assets alone rolled back at **20:46:44Z**, retaining the same shared configuration/storage and all **19 migrations**. After the same bounded wait, **actual HTTPS v1.0.1 HTML/CSS/JS** passed at **20:49:04.943Z**. The same browser session retained saved feedback, then logout/guest JSON 401 passed. Historical table counts/digests, original application key/shared environment, report identity, all 40 attempts, the one delivery and settled accounting were preserved. The candidate was **not reactivated after failed acceptance**; safe final public code is **v1.0.1 / `7806994bfe639ffc3185f6d3dd742511d36b9870`**.

VERIFIED GitHub readback: v1.0.0/v1.0.1 tag targets, publication dates and four-asset inventories retained their recorded identities/archive digests; no release was edited. No v1.0.2 tag/release or release-workflow dispatch was created.

**BLOCKED / NOT ACCEPTED:** live recommendation acceptance and owner receipt. The one-run/one-email allowance is now consumed; **do not automatically rerun or resend**. Next work is a separately reviewed offline investigation of the evidence/output/incomplete-response failures and authorized delivery/nonreceipt investigation. Any changed source needs new exact-candidate checks; another paid acceptance run or external send needs a new bounded decision. The existing conditional opt-in/publication authorization cannot make a failed run or nonreceipt pass. Phase 6 stays incomplete.

Protected host recovery at `~/apps/fotp-demo/recovery-phase64-19c92a463b99-20261030/` retains SQL/environment/storage, original state, per-APPEND receipts, before/after mailbox hashes, `actual-attempt-provenance.json`, immutable report, `owner-receipt-observation.json` and `final-handoff.json`. The disabled staging overlay is retained there as `staging-runtime.disabled.env`; do not source it as an enabled configuration. The four verified files remain in protected `~/apps/fotp-demo/incoming-phase64-19c92a463b99/`; the inactive candidate release is retained. No protected values or raw transport errors were copied into these documents. Repository evidence updates in this continuation remain local on the checkpoint branch; PR #81 was not pushed or merged, and the frozen candidate was not changed.

## One-item diagnostic and offline schema correction - 2026-10-31

The preceding 2026-10-30 no-further-diagnostic statements remain historical. The owner subsequently approved **one DEMO-001 diagnostic: one count, one inference, no retry or email**, and explicitly clarified consent to the existing temporary full-run/item reservation mechanics. Before dispatch, the dedicated UTC ledger showed zero settled/reserved, no work or uncertainty, and all public switches disabled. Official selected-model settings and rates were rechecked unchanged. The diagnostic reused the already approved/stored synthetic input and snapshot through the real ledger, counting, provider and validator; it did **not** repeat IMAP retrieval/APPEND, web admission, scheduler/worker acceptance, host deployment or migrations.

| Diagnostic evidence | VERIFIED application/provider result |
| --- | --- |
| Unmodified candidate / run | `19c92a463b99b8eee21469ad290399875ac08102` / `01m69rgs00twm1rpczfnsqj9nt` |
| Single attempt / outcome | `01m69rgs1ne76vkeem1jgqq83z` / known `demo_ai.evidence_invalid`, terminal failed |
| Count / inference / retry / email | **1 / 1 / 0 / 0**; diagnostic retry suppressed, no recommendation fabricated |
| Original reservation mechanics | 75,680 micro-USD admission pool; one selected item reduces to 3,784; one dispatch reserves 1,892; all remaining reservation released after known terminal settlement |
| Full input count / request hash | **1,111** / `4441c7293c7b13349777dca3faabfdc19b8fbf659255beb12dc9889fcf2f85b6` |
| Provider request / returned model | `req_376ddc3cdcfa456db39045457806ca09` / `gpt-6-luna` |
| Disjoint actual usage | **3 ordinary input / 0 cache-read / 1,108 cache-write / 304 output tokens** |
| Modeled inference settled / remaining reserved | **291 micro-USD = USD 0.000291 / 0**; no uncertainty or jobs |
| Counting fee | **NOT VERIFIED; null**, separately from inference settlement |

Protected temporary successful synthetic-response inspection established exact valid job quotes under **`field: "description"`**, while the unchanged validator requires **`input.description`**. Profile references were correctly namespaced. Local offline replay reproduced the original rejection; changing only the job-field prefixes in an **offline causal control** passed the unchanged shape, exact-quote, missing-information and hard-constraint validators. No production/model result was repaired. Xdebug launches completed without pausing; breakpoint/variable inspection is **not** claimed. This proves one schema/prompt-contract ambiguity, **not** every historical evidence failure or the 17 output-invalid/one incomplete outcomes.

Under the subsequent explicit offline-fix decision, `EvaluationOutput` now shares its existing non-null evidence-field map between schema and exact-quote validation. Schema `ai-evaluation-v2` / provider format `ai_evaluation_v2` constrains `field` to actual `input.*` / `profile.*` names and `missing_information` to the existing bare input-field/criterion IDs. Descriptions explain exact substring/namespace requirements. Validators still reject bare/unknown evidence, invented quotes and unsupported missing identifiers; no output normalization, scoring/rubric/profile/prompt/model/rate/cap change. The schema version enters the admission version hash, and the exact input-dependent schema enters the full counted/inferred request hash. Old counts cannot certify this new request.

**New local offline evidence:** focused provider/counting tests **50 tests / 1,324 assertions PASS**, including all 20 authored inputs, rejection of observed bare names, valid namespaced job/profile quotes, preserved invented-quote rejection and identical counted/inferred schema. Dirty PHP formatting and project PHPStan pass. Existing 320-test/full-browser/coverage CI remains evidence only for the frozen original revision; no fresh whole-suite/coverage, main CI or live v2 inference is claimed.

**OPERATOR-REPORTED:** the owner observed USD **0.01** for the earlier full run; this is not a verified invoice/fee breakdown and does not replace its measured modeled inference USD **0.008835**. The owner also supplied a Support reply acknowledging that general API no-training-by-default/abuse-log rules do not answer counting-specific questions. Successful/failed counting fees, account/tier conditions, Response/cache/application state, endpoint retention/deletion/backups/exceptions and effective dates remain **NOT VERIFIED**. Counting is not proved free and Responses `store=false` is not transferred onto the counting endpoint. Support requires no test requests/screenshots to clarify policy; no inquiry was reopened. The existing accepted-risk exception remains unchanged.

At **2026-10-31T20:01:21Z**, host readback verified original historical row digests and the immutable first report hash unchanged, one diagnostic item/attempt, no uncertainty/reservations/jobs, still one original delivery, 19 migrations, disabled private intake/all four switches, and current v1.0.1 code. Protected temporary response/input copies were removed after sanitized causal/provenance evidence was written; diagnostic intent/outcome and original accounting/recovery remain retained. No new SMTP connection/send or positive receipt occurred. PR #81 remains OPEN at `4cac97bf0f49acb86607ab6c12d53d02917346a9`; local evidence/source changes have not been published into it or the frozen candidate.

Before the sheet/roadmap edits, protected read-only shared-original comparisons at **20:00:42Z / 20:00:44Z** confirmed unchanged remote baselines: sheet `71d35f9f917f61c1fd9c7007988b73a909502b1494c4852dce3210b2329858d9`, roadmap `10ce119dc3f834b56351ee75928207cf9528024f2869755004166c347862fc21`. Pre-edit local hashes were `08132134ee154b33a606beff0458fc3f8fde1a73637c9790edb41329f05d635d` / `6b0c774587a21e44b5c6ea00fb84d5ecc121ae8f804eeaad8908689fab694586`. No files/sync state were replaced, force-pulled or shared-published.

**Remaining gate:** the newly approved diagnostic allowance is consumed. The offline fix is not a released/accepted candidate. Further integration/deployment and a new exact archive with independent checks need a separately reviewed next scope before additional paid validation; no automatic repeat/send. Other historical failure causes, recommendation acceptance and owner nonreceipt remain unresolved. v1.0.1 stays public, opt-in/counting/scheduler/delivery disabled, v1.0.2 unpublished and Phase 6 incomplete.

Final offline recheck still passed **50 tests / 1,324 assertions** after PHP formatting; `git diff --check` passed. At **2026-10-31T20:04:32.298Z**, certificate-validated actual public HTTPS CSS/JS matched the v1.0.1 release files byte-for-byte, with the current release identity unchanged. No app navigation/admission/provider/mailbox path was invoked for this read-only asset check. Disposable local diagnostic helper/replay/response/input files and their empty directory were removed; the original four-file candidate artifact remains retained.
