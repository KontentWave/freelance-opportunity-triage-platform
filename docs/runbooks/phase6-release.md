# Phase 6.4 owner delivery and bounded release acceptance

**2026-10-30: offline implementation; live/release acceptance PENDING.** This is an execution proposal, not authorization or evidence of live success. The public feature, counting, scheduler and owner-delivery switches remain disabled. Phases 1-5, previous releases, historical audits and the unsuccessful product-feasibility result are unchanged.

## Baseline and candidate

- Repository baseline: `eaa26d1f8b81ed3fcd6d2f2fca81ac7339677c15`, including merged 6.3 PR #78. Existing 6.3 CI and the guarded shared-original comparison are recorded in [readiness](phase6-readiness.md#slice-63-final-integration-outcome---2026-10-30).
- Review branch: `feat/phase64-owner-delivery`. The exact frozen PR-head SHA and passing protected checks must accompany the grouped approval. Any code change invalidates that candidate's checks.
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

## One grouped execution decision

**PENDING:** authorize the frozen PR candidate's normal merge; exact resulting main push CI/artifact verification; the setup/acceptance/rollback/release sequence below; at most one owner report email; and the deliberate opt-in after acceptance. No action may bypass a failed audit/check, change code/model/rates/caps, use private/retired targets, or repeat the run to improve results.

Collect together:

1. Approval of this bounded sequence and proposed unused **v1.0.2**, or keep everything disabled/offline.
2. Protected SMTP host, port 465/587, credentials and approved From, supplied through owner-only host files/environment, **not chat**. The pre-existing staged recipient stays the sole destination; do not ask for it again.
3. Approval to create/use a dedicated folder **`FOTP-Authored-Demo-Phase6`** in the previously approved demo mailbox and IMAP APPEND the exact 20 committed `authored-demo-v2` RFC822 files (or an owner import of those same files). No SMTP fabrication, real alerts, unrelated folder edits or weakening approved hashes.
4. Permission for protected on-demand demo backup/recovery preparation and a temporary bounded loopback staging server/scheduler, plus a short public code-switch/rollback maintenance window. No private changes.

### Bounded operations and budget

- Exactly **one acceptance run**, at most 20 approved unique fictional sources, original 30-minute deadline. No repeat whole run after failure/uncertainty.
- At most one existing classified retry/item (two attempts maximum), only for existing approved classifications. Count/inference share concurrency one, rolling 30 operations/minute and >=2-second spacing; no separate counting experiments. At most 40 inference attempts and their request-bound counts, not a new unconditional retry allowance.
- Full-run inference reservation **75,680 micro-USD = USD 0.075680** (both attempts, margin included). Before admission, inspect all-day unresolved reservations plus current UTC settled ledger. There are presently no feature tables, so expected fresh capacity is USD 1.000000, leaving **USD 0.924320** after this reservation, **conditional on the new ledger check**; no observed live ledger balance is claimed. Preserve uncertainty across days. Stop additional paid dispatch on uncertain accounting.
- USD 1.00/day bounds modeled inference only. Counting fees remain independently unknown/null and endpoint retention unverified. Report measured inference usage/cost from actual attempts separately; prepaid balance/no recharge is not a hard cap.
- At most **one external report email**, no retry after SMTP ambiguity. Duplicate triggers must return stored state and produce no additional send. Owner receipt requires authorized evidence or explicit owner report.
- One bounded setup/acceptance window (maximum 45 minutes for helpers; run deadline remains 30 minutes), with automatic no-work deadline for the temporary scheduler. No permanent helper/cron until explicitly accepted opt-in. Cleanup removes only this task's helper files/owned cron entry; preserve mailbox sources, successful results, historical review data and sent/uncertain liabilities.

### Approved-sequence checklist (not yet executed)

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

**NOT VERIFIED / NOT EXECUTED:** corpus population/retrieval, paid count/inference, live worker/scheduler, SMTP acceptance, owner receipt, migration/backup/restore, host changes, disable/code rollback rehearsal, activation and release publication. Newly incurred inference/email cost is **USD 0** because none was executed; unknown historical counting charges remain unverified. No new release or owner receipt is claimed. Phase 6 cannot be marked complete until these applicable criteria have actual evidence.
