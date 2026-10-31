# ADR-011: Conditional Database-Queue Execution and Recovery

- **Date:** 2026-10-27
- **Decision owner:** Marcel
- **Status:** APPROVED planning design and detailed limits; bounded host mechanism empirically supported, integrated application acceptance PENDING
- **6.2 acceptance update (2026-10-29):** Backend/offline scope ACCEPTED under ADR-010's counting-risk exception; [review evidence and owner-approved closure](../runbooks/phase6-readiness.md#slice-62-acceptance-closure---2026-10-29). Integrated worker/PEEK/live-service acceptance remains 6.4; no schedule or runtime activation.
- **Scope:** Slice 6.1 design and authorized temporary host probes; no application worker/schedule/configuration activation

## Context and recommendation

Use the existing Laravel monolith and MariaDB-backed queue, with a **bounded worker invoked by the scheduler through shell crontab**. No Node service, Redis, persistent daemon or infrastructure migration is justified. The [evidence record](../runbooks/phase6-readiness.md#feasibility-evidence) directly verifies temporary minute-crontab invocation, PHP 8.4.27/extensions/pcntl, timeout-wrapper execution and overlap exclusion on the actual public account. A separately authorized continuity probe recorded **176** pre-reconnection heartbeats from **12:47:01 to 15:42:01 UTC on 2026-10-27**, with no missed minute slots and 59-61-second intervals; the last was **2h 54m 59s** after the agent disconnected. This supports unattended execution over that observed window, without an agent SSH/GPT observer. It does not establish exact provider-side basic-shell expiration, other operator activity, indefinite availability or an operating Laravel scheduler/database worker. Both probes were removed and the absent original crontab restored; no premium-console requirement was demonstrated. Permanent application scheduling still requires separate activation/integrated acceptance; neither private-host SSH nor September's old-host cron proof substitutes for current observations.

Repository configuration supplies the database queue and `jobs`, `job_batches`, `failed_jobs` migration, but `after_commit=false` and default `retry_after=90`. Existing scheduling only polls private mailbox intake when enabled. Do not reuse/enable that intake or claim that a configured queue is an operating worker.

## Limits

| Control | Value | Decision status |
| --- | --- | --- |
| Sources/run, global active runs, queued runs | 20; 1; 2 (60 total item slots including active) | APPROVED |
| Provider concurrency / frequency | 1; at most 30 requests in any rolling minute, at least 2 seconds apart | APPROVED |
| Run/queue deadline | 30 minutes from admission, including queue wait; partial/failure on expiry | APPROVED |
| Per-session and per-IP admission | 1 trigger / 5 minutes and 3 / hour for each key; global 6 admissions / hour | APPROVED |
| Status polling | At most 1 request / 2 seconds/session/run | APPROVED |
| Candidate scan / body size | Last 100 numeric UID positions; maximum 100 bodies, 64 KiB each / 6,553,600 bytes total | APPROVED |
| Normalized payload / provider tokens | 12 KiB; 8,000 total input; 1,440 output | APPROVED; [cost rationale](ADR-010-demo-ai-provider-and-budget.md#estimates-and-ceiling) |
| IMAP / intake deadline | Connect 5 seconds; socket inactivity 5 seconds within a 20-second monotonic work budget plus 5 seconds cleanup | APPROVED |
| AI connect / total request / job timeout | 5 / 25 / 45 seconds; database waits at most 5 seconds, cleanup within remaining job time | APPROVED |
| Retry policy | At most 1 retry/item; 30-second delay or longer Retry-After within the original deadline; no immediate nested HTTP retries | APPROVED |
| Worker invocation | Every minute, stop when empty, `--max-time=40 --timeout=45 --sleep=1 --tries=1`, dedicated `demo-ai` queue | APPROVED design, conditional on host |
| Process / lease / queue reservation | Worker may finish its last job after max-time: at most 85 seconds, outer kill after 100 + 5-second grace; provider lease 120 seconds; queue retry_after 150 seconds; scheduler lock 180 seconds | APPROVED design, must prove host hard-stop and pcntl support |
| Stale/recovery check | Every minute; stale processing after 150 seconds; uncertain paid attempt never blindly rerun | APPROVED |
| Report retention / kill switch | Seven days from finalization; feature disabled by default; seeded demo remains usable | APPROVED |
| Global daily API usage | USD 1.00, midnight UTC, retries/uncertainty included | APPROVED |

Thirty-minute deadlines are not success guarantees. In particular, 40 maximum-duration attempts plus cron gaps can exceed the deadline; expire honestly with retained completed items rather than extend it or drop cost accounting. Queue capacity/rate limits may reject earlier than the daily ceiling.

The dedicated worker must use a demo-only database connection/queue configuration with `retry_after=150`, dispatch after the state transaction commits, and not drain private/default queues. `--max-time` is checked between jobs; it is **not** a hard per-process cutoff. Require verified OS/provider hard termination (for example an available timeout utility) and pcntl timeout support before accepting this design. If those are unavailable, return to the owner with a narrower execution decision; do not quietly fall back to synchronous web requests or a new service.

Use scheduler overlap protection backed by the demo database cache and an application database provider-slot lease/fencing token. Expiration alone cannot authorize a second call when a previous paid attempt is uncertain. Check the kill switch at admission and before each call; disabling blocks new work, cancels unstarted allocations, but retains sent/uncertain liabilities. A running remote request cannot be recalled.

## Minimum durable data contracts (APPROVED design)

No schema/migration is created in this slice. Implement only these feature-owned records in Slice 6.2:

- **Run:** ULID, server-owned demo workspace/session ownership, request-idempotency hash, status (`queued`, `running`, `completed`, `partial`, `failed`, `rejected`), admitted/start/heartbeat/deadline/finalization/expiry times, source namespace and UID snapshot, ordered source/input snapshot hashes, prompt/profile/parser/schema/model/pricing versions, counts and safe failure code. Store approved public input/profile snapshots, not private file paths.
- **Item:** run/workspace FK and ordinal, transport/logical source identities, normalized public input/hash, state (`pending`, `in_flight`, `retry_wait`, `succeeded`, `failed`, `uncertain`, `expired`), attempt count, retry time, lease/fencing token, immutable validated result or safe failure, usage/cost and provenance. Unique `(run, source_namespace, source_id)` and `(run, ordinal)`. Reuse normalized DTO ideas, not private opportunity/provider identities.
- **Attempt/budget ledger:** unique `(item, attempt_number)`, dispatch day, pricing snapshot, reserved/settled micro-USD, in-flight/known/uncertain outcome, provider request ID and usage, timestamps; one global budget row per UTC day and one global provider-slot lease. No raw provider exceptions or content in operational logs.

Queue payloads contain feature IDs only. Lock scoped run/item rows before state transitions; all lookups and report routes enforce server-selected demo workspace and owning session. Foreign/missing identifiers return the same not-found response. CSRF/session checks and rate limits apply to triggers; no visitor-controlled source, provider, profile or recipient.

HTTP request idempotency is scoped to the owning session/workspace plus submitted server-issued key and version set. Repeated submission returns the existing run without new budget/dispatch. A deliberate later request can create a fresh evaluation; never label a cached result a fresh live call. Queue retries must re-read state; uniqueness locks alone do not ensure exactly-once billing.

Run finalization is `completed` only after discovery succeeds and all selected items succeed; `partial` requires at least one successful item plus failures/expiry; no successful items means `failed`, while zero eligible items is `completed` with explicit empty counts. Admission failures are `rejected`. Intake timeout before a committed snapshot fails without dispatch. Selection/skip/reject counts are separate from AI successes. An uncertain item can finalize a partial/failed visible report while its minimal cost ledger remains unresolved.

After host/process loss, recover only unstarted work with valid reservations and deadline. Investigate stale in-flight work as uncertain; do not clear locks or retry blindly. Report cleanup after seven days removes only feature-owned content/files, not historical evaluations, feedback, audit evidence or unresolved ledger liabilities. Later delivery uses a separate unique `(run, report_hash, recipient_config_version)` record and cannot restart evaluation. An ambiguous SMTP outcome must remain uncertain without blind resending; a local uniqueness key alone cannot guarantee exactly-once external delivery.

## Acceptance boundary

### 6.4 delivery persistence and code rollback

The additive `demo_ai_deliveries` migration implements separate delivery state with **run-level primary uniqueness**, deliberately stronger than the planned `(run, report hash, recipient configuration version)` tuple. Hash/version identities remain recorded, but configuration rotation cannot authorize a duplicate copy. The synchronous operator command commits `sending` before SMTP; process loss or transport ambiguity remains uncertain without automatic resend. Recovery/receipt commands never send. No mail job is queued, so evaluation queue redelivery cannot resend a report. See the [state contract and approval-gated execution plan](../runbooks/phase6-release.md). Code rollback preserves all feature migrations/rows and sent/uncertain liabilities; `down()` is destructive and is not a production rollback procedure.

Slice 6.2 requires offline tests for reservation races, workspace/session isolation, idempotency, bounded scans, unknown/refusal/malformed results, expired/partial runs and kill-switch recovery. Worker/scheduler execution on the correct host, effective provider access, PEEK behavior and one recipient delivery need separate evidence and authorization. No host configuration, schedules, jobs, migrations, paid calls or emails are activated by this ADR.

## Local implementation details - 2026-10-28

Feature tables and tests now exist locally, not on the host. A stable singleton control row serializes UTC budget-row creation and provider fencing; transactions then lock day rows in date order **before** run/item/attempt rows. This prevents a midnight row-creation race while retaining the approved budget-before-run lock order. Current-day settled cost plus the sum of all outstanding day reservations represents the ceiling; the same liability is never counted again as a separate duplicate carry. A pre-send claim crossing midnight transfers its reservation transactionally and rechecks the new day's headroom.

The dedicated `demo-ai` queue and cache aliases use the guarded default demo database, ignoring private/default queue/cache overrides. Identifier-only jobs dispatch after commit. A run-level discovery token fences suspended old intake processes after unstarted-work recovery. Minimal rows survive seven-day content cleanup; cleanup is chunked to 100 runs and a 20-second work budget. Evidence-referenced reconciliation is an internal application operation, not a public endpoint or automatic retry.

Both scheduler hooks remain disabled by default; their application/database-worker/outer-timeout acceptance is not claimed. Source eligibility and schema/framing token proof remain separate requirements; the latter is an explicit [unclosed 6.2 blocker](../runbooks/phase6-readiness.md#local-slice-62-implementation-checkpoint---2026-10-28).

Research on 2026-10-29 identified the documented authenticated Responses input-count endpoint; the owner then approved bounded integration and conditional counting-only verification. See [ADR-010's remaining evidence gate](ADR-010-demo-ai-provider-and-budget.md#whole-input-counting-approval-gate). The offline implementation performs count and inference under the same committed attempt/provider fence, counts both toward the existing rolling 30-operations/minute cap, and waits two seconds before fresh inference authorization. Count proof is bound to the request hash and immutable snapshots. The original maximum job/request/lease/queue limits remain: a monotonic 40-second work deadline leaves five seconds within the 45-second job for settlement, with inference shortened to remaining time when needed. No database locks are held during HTTP or spacing waits.

The undeployed feature migration now includes nullable `inference_authorized_at`; the initial `dispatched_at` represents the conservatively allocated preflight operation, refreshed at preflight authorization. Recovery after process loss still retains the full attempt maximum conservatively, even if loss might have happened during free counting before inference. A known counting failure is zero-cost only under the explicit zero-charge policy gate, releases its unused allocation and does not retry or infer. Budget rollover is rechecked after counting. No request cap, attempt/retry maximum, reservation, schedule or deployed schema was changed. Actual external counting remains blocked pending policy evidence; 6.2 remains incomplete.

### Subsequent accepted-risk path - 2026-10-29

The [later owner decision and implementation](ADR-010-demo-ai-provider-and-budget.md#accepted-risk-implementation-and-accounting-scope---2026-10-29) supersede provider confirmation as a mandatory prerequisite. In accepted-risk mode, counting fees are unknown, not zero: the unchanged USD 1.00/day ledger/reservations cover modeled inference only, not a proven total-provider-charge ceiling. Existing attempt JSON durably stores the frozen policy, count-authorization time, null counting fee/unverified status and inference-only settlement scope; failures never retry or infer, and releasing unused inference allocations does not settle counting fees. Policy replacement/revocation blocks dispatch. Inference uncertainty/recovery/reconciliation, UTC rollover, concurrency, 30/minute frequency, two-second spacing, deadlines and all numeric limits remain intact. No migration, schedule/worker activation or host change was needed. Offline 6.2 work is ready for acceptance review; integrated 6.4 acceptance is still separate.

### Integrated bounded execution and disabled rollback evidence - 2026-10-30

The exact candidate's real application was served only on host loopback 8014 through authenticated SSH forwarding. A protected, process-only runtime overlay left the public shared environment disabled and unchanged. The temporary owned minute cron exercised real `schedule:run`, dedicated database worker/recovery, fixed 45-minute helper deadline and existing worker/timeout/overlap controls. One real web admission retained its original **20:24:05Z-20:54:05Z** deadline and finalized **failed at 20:32:18Z**. Its original-intent replay returned the same run, not another admission. Forty known attempts exhausted only the existing two-per-item bound; no new run or paid diagnostic retry followed.

Recovery/disable quiesced work; all inference usage settled with zero outstanding reservations, while unknown counting fees remained retained/unverified. Owned cron was removed to its absent baseline, isolated web/tunnel processes stopped and actual port closure checked. No permanent schedule remains. Both code versions used explicit `/usr/bin/php8.4` **8.4.27**; host default PHP was not substituted.

Protected backup/readability checks preceded the three additive migrations; **isolated database restore was not proved**. Disabled candidate then original v1.0.1 were verified through actual HTTPS HTML/CSS/JS after bounded 130-second cache-convergence waits. Original session/feedback, historical rows/key/shared environment, all 19 migrations, feature report/40 attempts/one delivery and accounting survived code-only rollback. No destructive down migration, old-state restore or schema reversal occurred. Public v1.0.1 is restored and all four switches disabled.

One failure-report SMTP acceptance/duplicate prevention was observed, but owner reports nonreceipt and no positive receipt was recorded. Live recommendation acceptance also failed (zero successful items). This supports bounded execution/rollback, **not overall acceptance or publication**. [Full evidence and next gate](../runbooks/phase6-release.md#resumed-bounded-execution---2026-10-30); no automatic rerun/resend under the consumed allowance.

### Additional diagnostic allowance and offline-only correction - 2026-10-31

The owner subsequently separately approved one direct operator DEMO-001 diagnostic and clarified approval of existing full-run/item reservation mechanics. It used one count/inference through the real ledger/provider/validator, explicitly no retry/email, and finalized known failed evidence validation with USD 0.000291 modeled inference and no outstanding reservations/jobs/uncertainty. Reused stored approved synthetic input is **not** renewed mailbox/browser/scheduler acceptance. No migrations, deployment, shared configuration, cron or public activation changed. Original history/report digests remain unchanged; all switches/private intake disabled and public v1.0.1 retained.

The later offline schema-fix approval permits local source/regression changes only, not additional paid calls, email, integration/deployment or publication. The newly consumed diagnostic cannot be silently repeated. Schema-v2 source needs separately reviewed integration/new exact-candidate checks and any further bounded live validation; owner receipt and recommendation acceptance remain open. [Actual diagnostic, local checks, causal limits and protected cleanup](../runbooks/phase6-release.md#one-item-diagnostic-and-offline-schema-correction---2026-10-31).
