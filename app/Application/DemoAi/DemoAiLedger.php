<?php

namespace App\Application\DemoAi;

use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\DemoAi\CountingPolicy;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\ProviderOutcome;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Triage\Support\CanonicalJson;
use App\Jobs\ProcessDemoAiRun;
use App\Models\DemoAiAttempt;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DemoAiLedger
{
    public const ATTEMPT_RESERVATION = 1892;

    public const RUN_RESERVATION = 75680;

    public const DAILY_LIMIT = 1000000;

    public function __construct(
        private readonly ApprovedDemoInputs $approved,
        private readonly DemoAiConfiguration $configuration,
        private readonly MonotonicClock $clock,
        private readonly CountingPolicy $countingPolicy,
    ) {}

    public function admit(string $workspace, string $sessionHash, string $ipHash, string $key): DemoAiRun
    {
        if (! config('demo_ai.enabled') || config('opportunity_review.mode') !== 'demo') {
            throw new DemoAiException('demo_ai.disabled');
        }
        if ($workspace !== $this->configuration->workspaceId()) {
            throw new DemoAiException('demo_ai.workspace_configuration');
        }
        $version = $this->approved->versionHash();
        $identity = hash('sha256', implode('|', [$workspace, $sessionHash, $key, $version]));

        return $this->transaction(function () use ($workspace, $sessionHash, $ipHash, $identity, $version): DemoAiRun {
            $existing = DemoAiRun::query()->where('idempotency_hash', $identity)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $now = CarbonImmutable::now('UTC');
            $day = $now->toDateString();
            $failure = null;
            $active = DemoAiRun::query()->whereIn('status', ['queued', 'running'])->count();
            $budget = DB::table('demo_ai_budgets')->where('day', $day)->first();
            $reserved = (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd');
            if ($active >= 3) {
                $failure = 'demo_ai.capacity';
            } elseif ((int) $budget->settled_micro_usd + $reserved + self::RUN_RESERVATION > self::DAILY_LIMIT) {
                $failure = 'demo_ai.budget_exhausted';
            } elseif (DB::table('demo_ai_control')->where('id', 1)->value('blocked')) {
                $failure = 'demo_ai.accounting_blocked';
            }
            $recent = DemoAiRun::query()->where('status', '!=', 'rejected')->where('admitted_at', '>', $now->subHour());
            if ((clone $recent)->count() >= 6
                || (clone $recent)->where('session_hash', $sessionHash)->count() >= 3
                || (clone $recent)->where('ip_hash', $ipHash)->count() >= 3
                || (clone $recent)->where('admitted_at', '>', $now->subMinutes(5))
                    ->where(fn ($query) => $query->where('session_hash', $sessionHash)->orWhere('ip_hash', $ipHash))->exists()) {
                $failure = 'demo_ai.admission_rate';
            }
            $approvedSnapshot = $this->approved->snapshot() + [
                'manifest_version' => 'authored-demo-v2', 'parser_version' => ApprovedDemoInputs::PARSER_VERSION,
                'schema_version' => ApprovedDemoInputs::SCHEMA_VERSION, 'model' => 'gpt-6-luna',
                'pricing_version' => 'openai-standard-2026-10-27',
            ];
            $run = DemoAiRun::query()->create([
                'workspace_id' => $workspace, 'session_hash' => $sessionHash, 'ip_hash' => $ipHash,
                'idempotency_hash' => $identity, 'status' => $failure === null ? 'queued' : 'rejected',
                'reservation_day' => $day, 'reserved_micro_usd' => $failure === null ? self::RUN_RESERVATION : 0,
                'approved_snapshot' => $approvedSnapshot,
                'approved_snapshot_sha256' => hash('sha256', CanonicalJson::encode($approvedSnapshot)),
                'version_hash' => $version, 'admitted_at' => $now, 'deadline_at' => $now->addMinutes(30),
                'finalized_at' => $failure === null ? null : $now,
                'expires_at' => $failure === null ? null : $now->addDays(7), 'failure_code' => $failure,
            ]);
            if ($failure === null) {
                DB::table('demo_ai_budgets')->where('day', $day)->increment('reserved_micro_usd', self::RUN_RESERVATION);
                $this->dispatch($run->id);
            }

            return $run;
        });
    }

    public function existing(string $workspace, string $sessionHash, string $key): ?DemoAiRun
    {
        $identity = hash('sha256', implode('|', [$workspace, $sessionHash, $key, $this->approved->versionHash()]));

        return DemoAiRun::query()->where('workspace_id', $workspace)->where('session_hash', $sessionHash)
            ->where('idempotency_hash', $identity)->first();
    }

    public function start(string $runId): ?DemoAiRun
    {
        return $this->transaction(function () use ($runId): ?DemoAiRun {
            $run = DemoAiRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->workspace_id !== $this->configuration->workspaceId()) {
                throw new DemoAiException('demo_ai.workspace_configuration');
            }
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return null;
            }
            if ($run->deadline_at->isPast() || ! config('demo_ai.enabled')) {
                $this->finishLocked($run, ! config('demo_ai.enabled') ? 'demo_ai.disabled' : 'demo_ai.deadline');

                return null;
            }
            if (DB::table('demo_ai_control')->where('id', 1)->value('blocked')) {
                $this->finishLocked($run, 'demo_ai.accounting_blocked');

                return null;
            }
            if ($run->status === 'queued' && DemoAiRun::query()->where('status', 'running')->exists()) {
                $this->dispatch($runId, 30);

                return null;
            }
            if ($run->status === 'running' && $run->source_snapshot === null) {
                return null;
            }
            $run->update([
                'status' => 'running', 'started_at' => $run->started_at ?? now('UTC'), 'heartbeat_at' => now('UTC'),
                'discovery_token' => $run->source_snapshot === null ? (string) Str::ulid() : $run->discovery_token,
            ]);

            return $run;
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function select(string $runId, array $snapshot, string $discoveryToken): void
    {
        $this->transaction(function () use ($runId, $snapshot, $discoveryToken): void {
            $run = DemoAiRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'running' || $run->source_snapshot !== null || $run->discovery_token !== $discoveryToken) {
                return;
            }
            if ($run->deadline_at->isPast() || ! config('demo_ai.enabled')) {
                $this->finishLocked($run, 'demo_ai.deadline');

                return;
            }
            $sources = $snapshot['sources'];
            if (count($sources) > 20) {
                throw new DemoAiException('demo_ai.scan_limit');
            }
            foreach ($sources as $index => $source) {
                DemoAiItem::query()->create([
                    'workspace_id' => $run->workspace_id, 'run_id' => $runId, 'ordinal' => $index,
                    'source_namespace' => $source['source_namespace'], 'source_id' => $source['source_id'],
                    'mailbox_key' => $source['mailbox_key'], 'uidvalidity' => $source['uidvalidity'], 'uid' => $source['uid'],
                    'body_sha256' => $source['body_sha256'], 'input_sha256' => $source['input_sha256'],
                    'raw_sha256' => $source['raw_sha256'], 'input_snapshot' => $source['input'], 'status' => 'pending',
                ]);
            }
            $needed = count($sources) * 2 * self::ATTEMPT_RESERVATION;
            $this->releasePool($run, $run->reserved_micro_usd - $needed);
            $run->update([
                'source_snapshot' => $snapshot,
                'source_snapshot_sha256' => hash('sha256', CanonicalJson::encode($snapshot)),
                'selected_count' => count($sources), 'heartbeat_at' => now('UTC'),
            ]);
            if ($sources === []) {
                $this->finishLocked($run);
            } else {
                $this->dispatch($runId);
            }
        });
    }

    /** @param array<string, mixed> $request */
    public function claim(string $itemId, array $request): ?DemoAiAttempt
    {
        return $this->transaction(function () use ($itemId, $request): ?DemoAiAttempt {
            $reference = DemoAiItem::query()->findOrFail($itemId);
            $run = DemoAiRun::query()->lockForUpdate()->findOrFail($reference->run_id);
            $item = DemoAiItem::query()->lockForUpdate()->findOrFail($itemId);
            if ($run->workspace_id !== $this->configuration->workspaceId()) {
                throw new DemoAiException('demo_ai.workspace_configuration');
            }
            if ($run->status !== 'running' || ! in_array($item->status, ['pending', 'retry_wait'], true)) {
                return null;
            }
            $now = CarbonImmutable::now('UTC');
            if (! config('demo_ai.enabled') || $run->deadline_at->lessThanOrEqualTo($now->addSeconds(25))) {
                $this->finishLocked($run, 'demo_ai.deadline');

                return null;
            }
            if ($item->retry_at !== null && $item->retry_at->isFuture()) {
                return null;
            }
            if ($item->attempt_count >= 2 || $run->reserved_micro_usd < self::ATTEMPT_RESERVATION
                || $item->input_snapshot === null
                || $run->approved_snapshot === null
                || hash('sha256', CanonicalJson::encode($run->approved_snapshot)) !== $run->approved_snapshot_sha256
                || hash('sha256', CanonicalJson::encode($item->input_snapshot)) !== $item->input_sha256
                || $run->version_hash !== $this->approved->versionHash()) {
                throw new DemoAiException('demo_ai.snapshot_or_allocation_invalid');
            }
            $slot = DB::table('demo_ai_control')->where('id', 1)->first();
            if ($slot->blocked || $slot->attempt_id !== null
                || ($slot->last_dispatch_at !== null && CarbonImmutable::parse($slot->last_dispatch_at, 'UTC')->greaterThan($now->subSeconds(2)))
                || $this->providerCallsInMinute($now) >= 29) {
                $this->dispatch($run->id, 2);

                return null;
            }
            $day = $now->toDateString();
            $budget = DB::table('demo_ai_budgets')->where('day', $day)->first();
            if ((int) $budget->settled_micro_usd + (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd') > self::DAILY_LIMIT) {
                $this->finishLocked($run, 'demo_ai.budget_exhausted');

                return null;
            }
            $origin = $run->reservation_day->toDateString();
            DB::table('demo_ai_budgets')->where('day', $origin)->decrement('reserved_micro_usd', self::ATTEMPT_RESERVATION);
            DB::table('demo_ai_budgets')->where('day', $day)->increment('reserved_micro_usd', self::ATTEMPT_RESERVATION);
            $run->decrement('reserved_micro_usd', self::ATTEMPT_RESERVATION);
            $fence = (int) $slot->fence + 1;
            $attempt = DemoAiAttempt::query()->create([
                'item_id' => $itemId, 'attempt_number' => $item->attempt_count + 1,
                'dispatch_day' => $day, 'status' => 'in_flight', 'fence' => $fence,
                'reserved_micro_usd' => self::ATTEMPT_RESERVATION,
                'pricing_snapshot' => ['version' => 'openai-standard-2026-10-27', 'ordinary_input' => 100, 'cache_read' => 10, 'cache_write' => 125, 'output' => 500, 'unit' => 'nano_usd_per_token'],
                'request_provenance' => [
                    'requested_model' => 'gpt-6-luna', 'request_sha256' => hash('sha256', CanonicalJson::encode($request)),
                    'service_tier' => 'default', 'reasoning_effort' => 'none', 'store' => false,
                    'cache_lifetime' => 'default-30m', 'max_input_tokens' => 8000, 'max_output_tokens' => 1440,
                    'counting_policy' => $this->countingPolicy->snapshot(),
                    'counting_cost_status' => 'not_dispatched', 'counting_cost_micro_usd' => 0,
                    'settled_cost_scope' => 'inference_only',
                ],
                'dispatched_at' => $now,
            ]);
            $item->update(['status' => 'in_flight', 'attempt_count' => $attempt->attempt_number, 'fence' => $fence]);
            $run->update(['heartbeat_at' => $now]);
            DB::table('demo_ai_control')->where('id', 1)->update([
                'attempt_id' => $attempt->id, 'fence' => $fence, 'lease_until' => $now->addSeconds(120), 'last_dispatch_at' => $now,
            ]);

            return $attempt;
        });
    }

    public function settle(string $attemptId, ProviderOutcome $outcome): void
    {
        $this->transaction(function () use ($attemptId, $outcome): void {
            $reference = DemoAiAttempt::query()->findOrFail($attemptId);
            $itemReference = DemoAiItem::query()->findOrFail($reference->item_id);
            $run = DemoAiRun::query()->lockForUpdate()->findOrFail($itemReference->run_id);
            $item = DemoAiItem::query()->lockForUpdate()->findOrFail($reference->item_id);
            $attempt = DemoAiAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $slot = DB::table('demo_ai_control')->where('id', 1)->first();
            if ($attempt->status !== 'in_flight' || $slot->attempt_id !== $attemptId || (int) $slot->fence !== $attempt->fence) {
                return;
            }

            $dayUncertain = ($outcome->uncertain || $outcome->costMicroUsd !== 0)
                && $attempt->dispatch_day->toDateString() !== CarbonImmutable::now('UTC')->toDateString();
            $failureCode = $dayUncertain ? 'demo_ai.dispatch_day_uncertain' : $outcome->failureCode;
            $uncertain = $dayUncertain || $outcome->uncertain || $outcome->costMicroUsd === null
                || $outcome->costMicroUsd > self::ATTEMPT_RESERVATION || $outcome->costMicroUsd < 0;
            $attempt->update([
                'status' => $uncertain ? 'uncertain' : 'known',
                'settled_micro_usd' => $uncertain ? null : $outcome->costMicroUsd,
                'usage' => $outcome->usage, 'failure_code' => $failureCode,
                'provider_request_id' => $outcome->requestId, 'returned_model' => $outcome->returnedModel,
                'settled_at' => $uncertain ? null : now('UTC'),
            ]);
            if ($uncertain) {
                $item->update(['status' => 'uncertain', 'failure_code' => $failureCode ?? 'demo_ai.usage_uncertain']);
                DB::table('demo_ai_control')->where('id', 1)->update(['blocked' => true]);
                $this->finishLocked($run, 'demo_ai.accounting_blocked');

                return;
            }
            DB::table('demo_ai_budgets')->where('day', $attempt->dispatch_day->toDateString())->decrement('reserved_micro_usd', self::ATTEMPT_RESERVATION);
            DB::table('demo_ai_budgets')->where('day', $attempt->dispatch_day->toDateString())->increment('settled_micro_usd', $outcome->costMicroUsd);
            $attempt->update(['reserved_micro_usd' => 0]);
            DB::table('demo_ai_control')->where('id', 1)->update(['attempt_id' => null, 'lease_until' => null]);
            $retryAt = CarbonImmutable::now('UTC')->addSeconds(max(30, $outcome->retryAfterSeconds));
            $retry = $outcome->result === null && $outcome->retryable && $item->attempt_count < 2
                && config('demo_ai.enabled') && $run->deadline_at->greaterThan($retryAt->addSeconds(25));
            $item->update([
                'status' => $outcome->result !== null ? 'succeeded' : ($retry ? 'retry_wait' : 'failed'),
                'result' => $outcome->result, 'failure_code' => $outcome->failureCode, 'retry_at' => $retry ? $retryAt : null,
            ]);
            $run->update([
                'heartbeat_at' => now('UTC'),
                'succeeded_count' => DemoAiItem::query()->where('run_id', $run->id)->where('status', 'succeeded')->count(),
                'failed_count' => DemoAiItem::query()->where('run_id', $run->id)->whereIn('status', ['failed', 'uncertain', 'expired'])->count(),
            ]);
            if ($outcome->failureCode === 'demo_ai.provider_permission') {
                $this->finishLocked($run, $outcome->failureCode);
            } elseif (! DemoAiItem::query()->where('run_id', $run->id)->whereIn('status', ['pending', 'retry_wait', 'in_flight'])->exists()) {
                $this->finishLocked($run);
            } else {
                $this->dispatch($run->id, $retry ? max(30, $outcome->retryAfterSeconds) : 2);
            }
        });
    }

    /** @param array{input_tokens: int, request_sha256: string}|null $inputCount */
    public function authorizeDispatch(string $attemptId, ?array $inputCount = null): bool
    {
        return $this->transaction(function () use ($attemptId, $inputCount): bool {
            $slot = DB::table('demo_ai_control')->where('id', 1)->first();
            $attempt = DemoAiAttempt::query()->findOrFail($attemptId);
            $item = DemoAiItem::query()->findOrFail($attempt->item_id);
            $run = DemoAiRun::query()->findOrFail($item->run_id);

            $authorized = config('demo_ai.enabled') && ! $slot->blocked && $slot->attempt_id === $attemptId
                && $attempt->status === 'in_flight' && (int) $slot->fence === $attempt->fence
                && $slot->lease_until !== null && CarbonImmutable::parse($slot->lease_until, 'UTC')->greaterThan(CarbonImmutable::now('UTC')->addSeconds(25))
                && $run->status === 'running' && $run->deadline_at->greaterThan(CarbonImmutable::now('UTC')->addSeconds(25));
            if (! $authorized || $attempt->inference_authorized_at !== null
                || ($attempt->request_provenance['counting_policy'] ?? null) !== $this->countingPolicy->snapshot()) {
                return false;
            }
            $now = CarbonImmutable::now('UTC');
            if ($inputCount !== null) {
                if ($inputCount['input_tokens'] < 1 || $inputCount['input_tokens'] > 8000
                    || $inputCount['request_sha256'] !== $attempt->request_provenance['request_sha256']
                    || $item->input_snapshot === null || $run->approved_snapshot === null
                    || hash('sha256', CanonicalJson::encode($item->input_snapshot)) !== $item->input_sha256
                    || hash('sha256', CanonicalJson::encode($run->approved_snapshot)) !== $run->approved_snapshot_sha256
                    || $run->version_hash !== $this->approved->versionHash()
                    || $this->providerCallsInMinute($now) >= 30
                    || CarbonImmutable::parse($slot->last_dispatch_at, 'UTC')->greaterThan($now->subSeconds(2))) {
                    return false;
                }
            }
            $day = CarbonImmutable::now('UTC')->toDateString();
            if ($attempt->dispatch_day->toDateString() !== $day) {
                $budget = DB::table('demo_ai_budgets')->where('day', $day)->first();
                if ((int) $budget->settled_micro_usd + (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd') > self::DAILY_LIMIT) {
                    return false;
                }
                DB::table('demo_ai_budgets')->where('day', $attempt->dispatch_day->toDateString())->decrement('reserved_micro_usd', self::ATTEMPT_RESERVATION);
                DB::table('demo_ai_budgets')->where('day', $day)->increment('reserved_micro_usd', self::ATTEMPT_RESERVATION);
                $attempt->update(['dispatch_day' => $day]);
            }
            $acceptedRisk = $attempt->request_provenance['counting_policy']['mode'] === CountingPolicy::ACCEPTED_RISK;
            $attempt->update($inputCount === null ? [
                'dispatched_at' => $now,
                'request_provenance' => array_replace($attempt->request_provenance, [
                    'counting_authorized_at' => $now->toIso8601String(),
                    'counting_cost_status' => $acceptedRisk ? 'unverified' : 'policy_zero_charge',
                    'counting_cost_micro_usd' => $acceptedRisk ? null : 0,
                ]),
            ] : [
                'inference_authorized_at' => $now,
                'request_provenance' => $attempt->request_provenance + [
                    'input_count' => $inputCount,
                    'counting_policy_evidence_sha256' => config('demo_ai.counting_policy_evidence_sha256'),
                ],
            ]);
            DB::table('demo_ai_control')->where('id', 1)->update(['last_dispatch_at' => $now]);

            return true;
        });
    }

    private function providerCallsInMinute(CarbonImmutable $now): int
    {
        return DemoAiAttempt::query()->where('dispatched_at', '>', $now->subMinute())->count()
            + DemoAiAttempt::query()->where('inference_authorized_at', '>', $now->subMinute())->count();
    }

    public function reconcile(string $attemptId, int $costMicroUsd, string $evidenceSha256, string $dispatchDay): void
    {
        if ($costMicroUsd < 0 || $costMicroUsd > self::ATTEMPT_RESERVATION || preg_match('/^[a-f0-9]{64}$/D', $evidenceSha256) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dispatchDay) !== 1
            || ! checkdate((int) substr($dispatchDay, 5, 2), (int) substr($dispatchDay, 8, 2), (int) substr($dispatchDay, 0, 4))) {
            throw new DemoAiException('demo_ai.reconciliation_invalid');
        }
        $this->transaction(function () use ($attemptId, $costMicroUsd, $evidenceSha256, $dispatchDay): void {
            $reference = DemoAiAttempt::query()->findOrFail($attemptId);
            $item = DemoAiItem::query()->findOrFail($reference->item_id);
            DemoAiRun::query()->whereKey($item->run_id)->lockForUpdate()->firstOrFail();
            DemoAiItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $attempt = DemoAiAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->status !== 'uncertain') {
                throw new DemoAiException('demo_ai.reconciliation_state');
            }
            if ($dispatchDay < $attempt->dispatched_at->toDateString() || $dispatchDay > CarbonImmutable::now('UTC')->toDateString()) {
                throw new DemoAiException('demo_ai.reconciliation_invalid');
            }
            DB::table('demo_ai_budgets')->where('day', $attempt->dispatch_day->toDateString())->decrement('reserved_micro_usd', $attempt->reserved_micro_usd);
            DB::table('demo_ai_budgets')->where('day', $dispatchDay)->increment('settled_micro_usd', $costMicroUsd);
            $attempt->update([
                'status' => 'known', 'reserved_micro_usd' => 0, 'settled_micro_usd' => $costMicroUsd,
                'settled_at' => now('UTC'), 'dispatch_day' => $dispatchDay,
                'request_provenance' => $attempt->request_provenance + ['reconciliation_evidence_sha256' => $evidenceSha256],
            ]);
            if (! DemoAiAttempt::query()->whereIn('status', ['in_flight', 'uncertain'])->exists()) {
                DB::table('demo_ai_control')->where('id', 1)->update(['blocked' => false, 'attempt_id' => null, 'lease_until' => null]);
            }
        }, $dispatchDay);
    }

    public function fail(string $runId, string $code, ?string $discoveryToken = null): void
    {
        $this->transaction(function () use ($runId, $code, $discoveryToken): void {
            $run = DemoAiRun::query()->lockForUpdate()->findOrFail($runId);
            if ($discoveryToken !== null && $run->discovery_token !== $discoveryToken) {
                return;
            }
            if (in_array($run->status, ['queued', 'running'], true)) {
                $this->finishLocked($run, $code);
            }
        });
    }

    public function recover(): void
    {
        $this->configuration->workspaceId();
        $deadline = $this->clock->now() + 20;
        $this->transaction(function () use ($deadline): void {
            $now = CarbonImmutable::now('UTC');
            $slot = DB::table('demo_ai_control')->where('id', 1)->first();
            if ($slot->attempt_id !== null && $slot->lease_until !== null
                && CarbonImmutable::parse($slot->lease_until, 'UTC')->lessThanOrEqualTo($now)
                && DemoAiAttempt::query()->whereKey($slot->attempt_id)->where('status', 'in_flight')
                    ->where('dispatched_at', '<=', $now->subSeconds(150))->exists()) {
                $reference = DemoAiAttempt::query()->findOrFail($slot->attempt_id);
                $itemReference = DemoAiItem::query()->findOrFail($reference->item_id);
                $run = DemoAiRun::query()->lockForUpdate()->findOrFail($itemReference->run_id);
                $item = DemoAiItem::query()->lockForUpdate()->findOrFail($reference->item_id);
                $attempt = DemoAiAttempt::query()->lockForUpdate()->findOrFail($reference->id);
                $attempt->update(['status' => 'uncertain', 'failure_code' => 'demo_ai.stale_attempt']);
                $item->update(['status' => 'uncertain', 'failure_code' => 'demo_ai.stale_attempt']);
                DB::table('demo_ai_control')->where('id', 1)->update(['blocked' => true]);
                $this->finishLocked($run, 'demo_ai.accounting_blocked');
            }
            foreach (DemoAiRun::query()->whereIn('status', ['queued', 'running'])->orderBy('id')->lockForUpdate()->get() as $run) {
                if (! config('demo_ai.enabled') || $run->deadline_at->lessThanOrEqualTo($now)) {
                    $this->finishLocked($run, ! config('demo_ai.enabled') ? 'demo_ai.disabled' : 'demo_ai.deadline');
                } elseif ($run->status === 'running' && $run->source_snapshot === null
                    && $run->heartbeat_at?->lessThanOrEqualTo($now->subSeconds(150))) {
                    // Discovery has no paid side effects; reselect only from a new namespace snapshot.
                    $run->update(['status' => 'queued']);
                    $this->dispatch($run->id);
                } else {
                    $this->dispatch($run->id);
                }
            }
            foreach (DemoAiRun::query()->where('expires_at', '<=', $now)->whereNull('purged_at')->orderBy('id')->limit(100)->lockForUpdate()->get() as $run) {
                if ($this->clock->now() >= $deadline) {
                    break;
                }
                DemoAiItem::query()->where('run_id', $run->id)->update(['input_snapshot' => null, 'result' => null]);
                $run->update(['approved_snapshot' => null, 'source_snapshot' => null, 'purged_at' => $now]);
            }
        });
    }

    private function finishLocked(DemoAiRun $run, ?string $code = null): void
    {
        $code ??= $run->finalized_at !== null ? $run->failure_code : null;
        $items = DemoAiItem::query()->where('run_id', $run->id)->orderBy('ordinal')->lockForUpdate()->get();
        foreach ($items as $item) {
            if (in_array($item->status, ['pending', 'retry_wait'], true)) {
                $item->update(['status' => 'expired', 'failure_code' => $code ?? 'demo_ai.cancelled']);
            }
        }
        $succeeded = $items->where('status', 'succeeded')->count();
        $failed = $items->count() - $succeeded;
        $emptySuccess = $run->source_snapshot !== null && $items->isEmpty() && $code === null;
        $status = $emptySuccess || ($succeeded > 0 && $failed === 0 && $code === null)
            ? 'completed' : ($succeeded > 0 ? 'partial' : 'failed');
        $this->releasePool($run, $run->reserved_micro_usd);
        $run->update([
            'status' => $status, 'succeeded_count' => $succeeded, 'failed_count' => $failed,
            'failure_code' => $code, 'finalized_at' => $run->finalized_at ?? now('UTC'),
            'expires_at' => $run->expires_at ?? now('UTC')->addDays(7),
        ]);
    }

    private function releasePool(DemoAiRun $run, int $amount): void
    {
        if ($amount < 0 || $amount > $run->reserved_micro_usd) {
            throw new DemoAiException('demo_ai.allocation_invalid');
        }
        if ($amount > 0) {
            DB::table('demo_ai_budgets')->where('day', $run->reservation_day->toDateString())->decrement('reserved_micro_usd', $amount);
            $run->decrement('reserved_micro_usd', $amount);
        }
    }

    private function dispatch(string $runId, int $delay = 0): void
    {
        ProcessDemoAiRun::dispatch($runId)->onConnection('demo-ai')->onQueue('demo-ai')->delay($delay)->afterCommit();
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function transaction(Closure $operation, ?string $additionalBudgetDay = null): mixed
    {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

        return DB::transaction(function () use ($operation, $additionalBudgetDay): mixed {
            // A stable singleton serializes UTC row creation and cross-day carry accounting.
            DB::table('demo_ai_control')->where('id', 1)->lockForUpdate()->firstOrFail();
            DB::table('demo_ai_budgets')->insertOrIgnore(['day' => CarbonImmutable::now('UTC')->toDateString()]);
            if ($additionalBudgetDay !== null) {
                DB::table('demo_ai_budgets')->insertOrIgnore(['day' => $additionalBudgetDay]);
            }
            DB::table('demo_ai_budgets')->orderBy('day')->lockForUpdate()->get();

            return $operation();
        }, 1);
    }
}
