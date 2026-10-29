<?php

namespace Tests\Feature;

use App\Application\DemoAi\DemoAiLedger;
use App\Domain\DemoAi\ProviderOutcome;
use App\Domain\Triage\Support\CanonicalJson;
use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Jobs\ProcessDemoAiRun;
use App\Models\DemoAiAttempt;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DemoAiLedgerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reserves_once_and_routes_identifiers_to_the_dedicated_after_commit_database_queue(): void
    {
        $this->freezeTime();
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        Queue::fake([ProcessDemoAiRun::class]);
        $workspace = Workspace::factory()->create();
        config(['opportunity_review.demo_user_id' => User::factory()->create(['workspace_id' => $workspace->id])->id]);
        $ledger = app(DemoAiLedger::class);
        $run = $ledger->admit($workspace->id, hash('sha256', 'session'), hash('sha256', 'ip'), 'key');
        $duplicate = $ledger->admit($workspace->id, hash('sha256', 'session'), hash('sha256', 'ip'), 'key');

        $this->assertSame($run->id, $duplicate->id);
        $this->assertDatabaseCount('demo_ai_runs', 1);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 75680, 'settled_micro_usd' => 0]);
        Queue::assertPushed(ProcessDemoAiRun::class, fn (ProcessDemoAiRun $job): bool => $job->runId === $run->id
            && $job->connection === 'demo-ai' && $job->queue === 'demo-ai' && $job->afterCommit === true);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_releases_unused_discovery_slots_and_settles_without_double_counting(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 3784]);
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertNotNull($attempt);
        $this->assertNull($ledger->claim($item->id, ['model' => 'gpt-6-luna']));
        $outcome = new ProviderOutcome(['recommendation' => 'APPLY'], ['ordinary_input' => 100], 10, null);

        $ledger->settle($attempt->id, $outcome);
        $ledger->settle($attempt->id, $outcome);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 10]);
        $this->assertDatabaseCount('demo_ai_attempts', 1);
    }

    #[Test]
    public function it_keeps_uncertain_liabilities_across_rollover_expiry_kill_switch_and_retention(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $ledger->settle($attempt->id, new ProviderOutcome(null, null, null, 'demo_ai.provider_uncertain', uncertain: true));
        $this->travel(8)->days();
        config(['demo_ai.enabled' => false]);

        $ledger->recover();

        $this->assertSame(1892, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => true, 'attempt_id' => $attempt->id]);
        $this->assertNull($item->fresh()->input_snapshot);
        $this->assertNull($run->fresh()->approved_snapshot);
        $this->assertSame('uncertain', $attempt->fresh()->status);
    }

    #[Test]
    public function it_rejects_capacity_without_an_extra_reservation(): void
    {
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        Queue::fake([ProcessDemoAiRun::class]);
        $workspace = Workspace::factory()->create();
        config(['opportunity_review.demo_user_id' => User::factory()->create(['workspace_id' => $workspace->id])->id]);
        $runs = [];
        foreach (range(1, 4) as $number) {
            $runs[] = app(DemoAiLedger::class)->admit($workspace->id, hash('sha256', "s{$number}"), hash('sha256', "ip{$number}"), "key{$number}");
        }

        $this->assertSame('rejected', $runs[3]->status);
        $this->assertSame('demo_ai.capacity', $runs[3]->failure_code);
        $this->assertSame(227040, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
        Queue::assertPushed(ProcessDemoAiRun::class, 3);
    }

    #[Test]
    public function it_reserves_a_second_attempt_only_after_the_original_retry_delay(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $ledger->settle($attempt->id, new ProviderOutcome(null, [], 10, 'demo_ai.output_invalid', retryable: true));
        $early = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertNull($early);
        $this->travel(30)->seconds();
        $retry = app(DemoAiLedger::class)->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertNotNull($retry);

        $ledger->settle($retry->id, new ProviderOutcome(null, [], 10, 'demo_ai.output_invalid', retryable: true));

        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseCount('demo_ai_attempts', 2);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 20]);
    }

    #[Test]
    public function it_retains_successful_results_and_reports_partial_when_another_item_fails(): void
    {
        [$ledger, $run, $item] = $this->selectedRun([1, 2]);
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));
        $this->assertSame(1, $run->fresh()->succeeded_count);
        $this->travel(2)->seconds();
        $second = DemoAiItem::query()->where('run_id', $run->id)->where('ordinal', 1)->firstOrFail();
        $attempt = $ledger->claim($second->id, ['model' => 'gpt-6-luna']);

        $ledger->settle($attempt->id, new ProviderOutcome(null, [], 10, 'demo_ai.refused'));

        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->failed_count);
        $this->assertSame(['recommendation' => 'APPLY'], $item->fresh()->result);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 20]);
    }

    #[Test]
    public function it_expires_unstarted_work_at_the_original_admission_deadline_without_billing(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $this->travel(30)->minutes();

        $ledger->recover();

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('demo_ai.deadline', $run->fresh()->failure_code);
        $this->assertSame('expired', $item->fresh()->status);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 0]);
    }

    #[Test]
    public function it_never_redispatches_a_stale_paid_attempt_even_after_its_lease_expires(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->travel(120)->seconds();
        $ledger->recover();
        $this->assertSame('in_flight', $attempt->fresh()->status);
        $this->travel(30)->seconds();

        $ledger->recover();

        $this->assertSame('uncertain', $attempt->fresh()->status);
        $this->assertFalse($ledger->authorizeDispatch($attempt->id));
        $this->assertDatabaseCount('demo_ai_attempts', 1);
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => true]);
        $this->assertSame(1892, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
    }

    #[Test]
    public function it_reauthorizes_a_claim_crossing_midnight_against_the_new_utc_day_without_a_refund_gap(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 23:59:58 UTC'));
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->travel(3)->seconds();

        $this->assertTrue($ledger->authorizeDispatch($attempt->id));
        $this->assertSame(3784, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
        $this->assertSame('2026-10-29', $attempt->fresh()->dispatch_day->toDateString());
        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));

        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-28', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 0]);
        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-29', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 10]);
    }

    #[Test]
    public function it_reconciles_only_an_uncertain_liability_with_an_explicit_evidence_reference_without_restarting_work(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $ledger->settle($attempt->id, new ProviderOutcome(null, null, null, 'demo_ai.provider_uncertain', uncertain: true));

        $ledger->reconcile($attempt->id, 1000, hash('sha256', 'controlled reconciliation evidence'), now('UTC')->toDateString());

        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 1000]);
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => false, 'attempt_id' => null]);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('uncertain', $item->fresh()->status);
    }

    #[Test]
    public function it_enforces_two_seconds_between_provider_calls_even_when_the_slot_is_free(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        DB::table('demo_ai_control')->where('id', 1)->update(['last_dispatch_at' => now('UTC')]);

        $this->assertNull($ledger->claim($item->id, ['model' => 'gpt-6-luna']));
        $this->assertDatabaseCount('demo_ai_attempts', 0);
        $this->travel(2)->seconds();
        $attempt = app(DemoAiLedger::class)->claim($item->id, ['model' => 'gpt-6-luna']);

        $this->assertNotNull($attempt);
        $this->assertDatabaseCount('demo_ai_attempts', 1);
    }

    #[Test]
    public function it_binds_count_evidence_to_the_claim_and_reauthorizes_inference_once_after_two_seconds(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertTrue($ledger->authorizeDispatch($attempt->id));
        $proof = ['input_tokens' => 8000, 'request_sha256' => $attempt->request_provenance['request_sha256']];
        $this->assertFalse($ledger->authorizeDispatch($attempt->id, $proof));
        $this->travel(2)->seconds();
        $this->assertFalse($ledger->authorizeDispatch($attempt->id, [
            'input_tokens' => 8001, 'request_sha256' => $proof['request_sha256'],
        ]));
        $this->assertFalse($ledger->authorizeDispatch($attempt->id, [
            'input_tokens' => 8000, 'request_sha256' => hash('sha256', 'a different request'),
        ]));

        $this->assertTrue($ledger->authorizeDispatch($attempt->id, $proof));

        $this->assertSame($proof, $attempt->fresh()->request_provenance['input_count']);
        $this->assertNotNull($attempt->fresh()->inference_authorized_at);
        $this->assertFalse($ledger->authorizeDispatch($attempt->id, $proof));
        $this->assertDatabaseHas('demo_ai_control', ['attempt_id' => $attempt->id, 'blocked' => false]);
        $this->assertSame(3784, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
    }

    #[Test]
    public function it_preserves_unknown_counting_costs_after_uncertain_inference_retention_and_reconciliation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00 UTC'));
        [$ledger, $run, $item] = $this->selectedRun();
        config([
            'demo_ai.counting_enabled' => true, 'demo_ai.counting_policy_mode' => 'owner_accepted_risk',
            'demo_ai.counting_policy_evidence_sha256' => null,
            'demo_ai.counting_risk_acceptance_reference' => 'ADR-010:2026-10-29:accepted-counting-risk',
        ]);
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertTrue($ledger->authorizeDispatch($attempt->id));
        $this->travel(2)->seconds();
        $this->assertTrue($ledger->authorizeDispatch($attempt->id, [
            'input_tokens' => 1127, 'request_sha256' => $attempt->request_provenance['request_sha256'],
        ]));

        $ledger->settle($attempt->id, new ProviderOutcome(null, null, null, 'demo_ai.provider_uncertain', uncertain: true));

        $this->assertDatabaseHas('demo_ai_control', ['blocked' => true, 'attempt_id' => $attempt->id]);
        $this->assertSame(1892, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
        $this->travel(8)->days();
        $ledger->recover();
        $this->assertNull($run->fresh()->approved_snapshot);
        $this->assertSame('unverified', $attempt->fresh()->request_provenance['counting_cost_status']);
        $this->assertNull($attempt->fresh()->request_provenance['counting_cost_micro_usd']);

        $ledger->reconcile($attempt->id, 175, hash('sha256', 'offline inference billing evidence'), '2026-10-29');

        $provenance = DemoAiAttempt::query()->findOrFail($attempt->id)->request_provenance;
        $this->assertSame('inference_only', $provenance['settled_cost_scope']);
        $this->assertSame('unverified', $provenance['counting_cost_status']);
        $this->assertNull($provenance['counting_cost_micro_usd']);
        $this->assertSame('2026-10-29T12:00:00+00:00', $provenance['counting_authorized_at']);
        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-29', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 175]);
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => false, 'attempt_id' => null]);
        Queue::assertPushed(ProcessDemoAiRun::class, 2);
    }

    /** @return array<string, array{bool}> */
    public static function riskRevocationStages(): array
    {
        return ['before count' => [false], 'after count' => [true]];
    }

    #[Test]
    #[DataProvider('riskRevocationStages')]
    public function it_rechecks_owner_acceptance_before_counting_and_before_inference(bool $countAuthorized): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        config([
            'demo_ai.counting_enabled' => true, 'demo_ai.counting_policy_mode' => 'owner_accepted_risk',
            'demo_ai.counting_policy_evidence_sha256' => null,
            'demo_ai.counting_risk_acceptance_reference' => 'ADR-010:2026-10-29:accepted-counting-risk',
        ]);
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        if ($countAuthorized) {
            $this->assertTrue($ledger->authorizeDispatch($attempt->id));
            $this->travel(2)->seconds();
        }
        config(['demo_ai.counting_risk_acceptance_reference' => null]);

        $authorized = $ledger->authorizeDispatch($attempt->id, $countAuthorized ? [
            'input_tokens' => 1127, 'request_sha256' => $attempt->request_provenance['request_sha256'],
        ] : null);

        $this->assertFalse($authorized);
        $this->assertNull($attempt->fresh()->inference_authorized_at);
        $this->assertSame($countAuthorized ? 'unverified' : 'not_dispatched', $attempt->fresh()->request_provenance['counting_cost_status']);
        $this->assertSame('ADR-010:2026-10-29:accepted-counting-risk', $attempt->fresh()->request_provenance['counting_policy']['risk_acceptance_reference']);
        Queue::assertPushed(ProcessDemoAiRun::class, 2);
    }

    /** @return array<string, array{string}> */
    public static function changedSnapshots(): array
    {
        return ['input' => ['input_snapshot'], 'profile' => ['approved_snapshot']];
    }

    #[Test]
    #[DataProvider('changedSnapshots')]
    public function it_rejects_snapshot_changes_after_counting_before_inference(string $field): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $model = $field === 'input_snapshot' ? $item : $run;
        $snapshot = $model->$field;
        $snapshot['unexpected'] = 'changed after counting';
        DB::table($model->getTable())->where('id', $model->id)->update([$field => CanonicalJson::encode($snapshot)]);
        $this->travel(2)->seconds();

        $this->assertFalse($ledger->authorizeDispatch($attempt->id, [
            'input_tokens' => 7000, 'request_sha256' => $attempt->request_provenance['request_sha256'],
        ]));

        $this->assertNull($attempt->fresh()->inference_authorized_at);
        $this->assertArrayNotHasKey('input_count', $attempt->fresh()->request_provenance);
    }

    #[Test]
    public function it_counts_both_preflight_and_inference_in_the_same_rolling_thirty_operation_limit(): void
    {
        [$ledger, $run, $item] = $this->selectedRun([1, 2]);
        DemoAiAttempt::factory()->count(14)->create([
            'status' => 'known', 'reserved_micro_usd' => 0,
            'dispatched_at' => now('UTC')->subSeconds(50), 'inference_authorized_at' => now('UTC')->subSeconds(48),
        ]);
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertNotNull($attempt);
        $this->travel(2)->seconds();
        $this->assertTrue($ledger->authorizeDispatch($attempt->id, [
            'input_tokens' => 8000, 'request_sha256' => $attempt->request_provenance['request_sha256'],
        ]));
        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));
        $second = DemoAiItem::query()->where('run_id', $run->id)->where('ordinal', 1)->firstOrFail();
        $this->travel(2)->seconds();

        $this->assertNull($ledger->claim($second->id, ['model' => 'gpt-6-luna']));

        $this->assertDatabaseCount('demo_ai_attempts', 15);
        $this->assertSame('pending', $second->fresh()->status);
    }

    #[Test]
    public function it_reauthorizes_inference_on_the_new_utc_day_after_a_free_count_crosses_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 23:59:58 UTC'));
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertTrue($ledger->authorizeDispatch($attempt->id));
        $this->travel(3)->seconds();

        $this->assertTrue($ledger->authorizeDispatch($attempt->id, [
            'input_tokens' => 7000, 'request_sha256' => $attempt->request_provenance['request_sha256'],
        ]));
        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));

        $this->assertSame('2026-10-29', $attempt->fresh()->dispatch_day->toDateString());
        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-28', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 0]);
        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-29', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 10]);
    }

    #[Test]
    public function it_prevents_a_recovered_discovery_from_accepting_a_stale_process_snapshot(): void
    {
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        Queue::fake([ProcessDemoAiRun::class]);
        $user = User::factory()->create(['workspace_id' => Workspace::factory()]);
        config(['opportunity_review.demo_user_id' => $user->id]);
        $ledger = app(DemoAiLedger::class);
        $run = $ledger->admit($user->workspace_id, hash('sha256', 'session'), hash('sha256', 'ip'), 'key');
        $first = $ledger->start($run->id);
        $this->travel(151)->seconds();
        $ledger->recover();
        $second = $ledger->start($run->id);
        $this->assertNotSame($first->discovery_token, $second->discovery_token);

        $ledger->select($run->id, ['sources' => []], $first->discovery_token);
        $ledger->fail($run->id, 'demo_ai.intake_failed', $first->discovery_token);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull($run->fresh()->source_snapshot);
        Queue::assertPushed(ProcessDemoAiRun::class);
    }

    #[Test]
    public function it_enforces_five_minute_and_three_per_hour_admissions_for_both_session_and_ip(): void
    {
        $this->freezeTime();
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        Queue::fake([ProcessDemoAiRun::class]);
        $user = User::factory()->create(['workspace_id' => Workspace::factory()]);
        config(['opportunity_review.demo_user_id' => $user->id]);
        $ledger = app(DemoAiLedger::class);
        $session = hash('sha256', 'session');
        $ip = hash('sha256', 'ip');
        foreach (range(1, 3) as $number) {
            $run = $ledger->admit($user->workspace_id, $session, $ip, 'key'.$number);
            $this->assertSame('queued', $run->status);
            $ledger->fail($run->id, 'demo_ai.controlled_presend_failure');
            $early = $ledger->admit($user->workspace_id, hash('sha256', 'other-session'), $ip, 'early'.$number);
            $this->assertSame('demo_ai.admission_rate', $early->failure_code);
            $this->travel(5)->minutes();
        }

        $sessionLimited = $ledger->admit($user->workspace_id, $session, hash('sha256', 'other-ip'), 'session-limit');
        $ipLimited = $ledger->admit($user->workspace_id, hash('sha256', 'third-session'), $ip, 'ip-limit');

        $this->assertSame('demo_ai.admission_rate', $sessionLimited->failure_code);
        $this->assertSame('demo_ai.admission_rate', $ipLimited->failure_code);
        $this->assertSame(0, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
        Queue::assertPushed(ProcessDemoAiRun::class, 3);
    }

    #[Test]
    public function it_limits_global_admissions_to_six_per_hour_even_after_previous_runs_finish(): void
    {
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        Queue::fake([ProcessDemoAiRun::class]);
        $user = User::factory()->create(['workspace_id' => Workspace::factory()]);
        config(['opportunity_review.demo_user_id' => $user->id]);
        $ledger = app(DemoAiLedger::class);
        foreach (range(1, 6) as $number) {
            $run = $ledger->admit($user->workspace_id, hash('sha256', 'session'.$number), hash('sha256', 'ip'.$number), 'key'.$number);
            $ledger->fail($run->id, 'demo_ai.controlled_presend_failure');
        }

        $run = $ledger->admit($user->workspace_id, hash('sha256', 'seventh'), hash('sha256', 'seventh'), 'key7');

        $this->assertSame('demo_ai.admission_rate', $run->failure_code);
        Queue::assertPushed(ProcessDemoAiRun::class, 6);
    }

    #[Test]
    public function it_keeps_worker_and_recovery_schedules_disabled_by_default_and_off_private_queues(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'demo-ai'));

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertFalse($event->filtersPass($this->app));
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(3, $event->expiresAt);
        }
        $this->assertSame(150, config('queue.connections.demo-ai.retry_after'));
        $this->assertSame('database', config('queue.connections.demo-ai.driver'));
        $this->assertTrue(config('queue.connections.demo-ai.after_commit'));
    }

    #[Test]
    public function it_stops_queued_discovery_and_releases_only_its_pool_after_an_uncertain_attempt(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $queued = $ledger->admit($run->workspace_id, hash('sha256', 'other-session'), hash('sha256', 'other-ip'), 'other-key');
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $ledger->settle($attempt->id, new ProviderOutcome(null, null, null, 'demo_ai.provider_uncertain', uncertain: true));

        $this->assertNull($ledger->start($queued->id));
        $this->assertSame('failed', $queued->fresh()->status);
        $this->assertSame('demo_ai.accounting_blocked', $queued->fresh()->failure_code);
        $this->assertNull($queued->fresh()->source_snapshot);
        $this->assertSame(1892, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
    }

    #[Test]
    public function it_does_not_extend_retention_when_recovery_later_marks_a_cancelled_attempt_uncertain(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        config(['demo_ai.enabled' => false]);
        $ledger->recover();
        $finalized = $run->fresh()->finalized_at;
        $expires = $run->fresh()->expires_at;
        $this->travel(151)->seconds();

        $ledger->recover();

        $this->assertSame('uncertain', $attempt->fresh()->status);
        $this->assertTrue($finalized->equalTo($run->fresh()->finalized_at));
        $this->assertTrue($expires->equalTo($run->fresh()->expires_at));
        $this->assertSame(1892, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
    }

    #[Test]
    public function it_accounts_for_a_late_known_result_without_erasing_disablement_or_extending_retention(): void
    {
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        config(['demo_ai.enabled' => false]);
        $ledger->recover();
        $expires = $run->fresh()->expires_at;
        $this->travel(2)->seconds();

        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));

        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame('demo_ai.disabled', $run->fresh()->failure_code);
        $this->assertTrue($expires->equalTo($run->fresh()->expires_at));
        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 10]);
    }

    #[Test]
    public function it_freezes_a_response_crossing_midnight_until_evidence_reconciles_its_actual_dispatch_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 23:59:58 UTC'));
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->assertTrue($ledger->authorizeDispatch($attempt->id));
        $this->travel(3)->seconds();

        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));

        $this->assertSame('uncertain', $attempt->fresh()->status);
        $this->assertSame('demo_ai.dispatch_day_uncertain', $attempt->fresh()->failure_code);
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => true]);
        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-28', 'reserved_micro_usd' => 1892, 'settled_micro_usd' => 0]);
        $this->assertNull($item->fresh()->result);
        $ledger->reconcile($attempt->id, 10, hash('sha256', 'controlled next-day dispatch evidence'), '2026-10-29');

        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-28', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 0]);
        $this->assertDatabaseHas('demo_ai_budgets', ['day' => '2026-10-29', 'reserved_micro_usd' => 0, 'settled_micro_usd' => 10]);
        $this->assertSame('2026-10-29', $attempt->fresh()->dispatch_day->toDateString());
        $this->assertSame('failed', $run->fresh()->status);
    }

    #[Test]
    public function it_releases_a_proven_unsent_cross_midnight_cancellation_without_freezing_accounting(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 23:59:58 UTC'));
        [$ledger, $run, $item] = $this->selectedRun();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        $this->travel(3)->seconds();
        config(['demo_ai.enabled' => false]);
        $this->assertFalse($ledger->authorizeDispatch($attempt->id));

        $ledger->settle($attempt->id, new ProviderOutcome(null, [], 0, 'demo_ai.dispatch_cancelled'));

        $this->assertSame('known', $attempt->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => false, 'attempt_id' => null]);
        $this->assertSame(0, (int) DB::table('demo_ai_budgets')->sum('reserved_micro_usd'));
        $this->assertSame(0, (int) DB::table('demo_ai_budgets')->sum('settled_micro_usd'));
    }

    /** @return array{DemoAiLedger, DemoAiRun, DemoAiItem} */
    private function selectedRun(array $numbers = [1]): array
    {
        $this->freezeTime();
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        Queue::fake([ProcessDemoAiRun::class]);
        $workspace = Workspace::factory()->create();
        config(['opportunity_review.demo_user_id' => User::factory()->create(['workspace_id' => $workspace->id])->id]);
        $ledger = app(DemoAiLedger::class);
        $run = $ledger->admit($workspace->id, hash('sha256', 'session'), hash('sha256', 'ip'), 'key');
        $ledger->start($run->id);
        $sources = [];
        foreach ($numbers as $number) {
            $source = app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path(sprintf('tests/Fixtures/Emails/demo/%03d.eml', $number))));
            $sources[] = $source + ['uid' => $number, 'uidvalidity' => 1, 'mailbox_key' => 'authored'];
        }
        $ledger->select($run->id, ['sources' => $sources], $run->fresh()->discovery_token);
        Queue::assertPushed(ProcessDemoAiRun::class);

        return [$ledger, $run, DemoAiItem::query()->where('run_id', $run->id)->firstOrFail()];
    }
}
