<?php

namespace Tests\Feature;

use App\Application\DemoAi\DemoAiLedger;
use App\Jobs\ProcessDemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

final class DemoAiConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function it_serializes_real_concurrent_admissions_at_the_budget_ceiling(): void
    {
        $workspace = $this->configure();
        DB::table('demo_ai_budgets')->insert(['day' => now('UTC')->toDateString(), 'settled_micro_usd' => 900000]);

        $outcomes = $this->race($workspace, false);

        $this->assertSame(1, count(array_filter($outcomes, fn (array $outcome): bool => $outcome['status'] === 'queued')));
        $this->assertSame(3, count(array_filter($outcomes, fn (array $outcome): bool => $outcome['failure'] === 'demo_ai.budget_exhausted')));
        $this->assertDatabaseHas('demo_ai_budgets', ['settled_micro_usd' => 900000, 'reserved_micro_usd' => 75680]);
    }

    #[Test]
    public function it_serializes_duplicate_requests_without_duplicate_runs_or_reservations(): void
    {
        $workspace = $this->configure();

        $outcomes = $this->race($workspace, true);

        $this->assertCount(1, array_unique(array_column($outcomes, 'id')));
        $this->assertDatabaseCount('demo_ai_runs', 1);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 75680]);
    }

    #[Test]
    public function it_dispatches_only_after_the_outer_state_transaction_commits_to_the_dedicated_queue(): void
    {
        $workspace = $this->configure();
        DB::beginTransaction();
        $run = app(DemoAiLedger::class)->admit($workspace, hash('sha256', 's'), hash('sha256', 'ip'), 'key');
        $this->assertDatabaseCount('jobs', 0);

        DB::commit();

        $this->assertDatabaseHas('jobs', ['queue' => 'demo-ai']);
        $payload = json_decode(DB::table('jobs')->value('payload'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($run->id, $payload['data']['command']);
        $this->assertStringNotContainsString('input_snapshot', $payload['data']['command']);
    }

    private function configure(): string
    {
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo']);
        $workspace = Workspace::factory()->create();
        config(['opportunity_review.demo_user_id' => User::factory()->create(['workspace_id' => $workspace->id])->id]);

        return $workspace->id;
    }

    /** @return list<array{id: string, status: string, failure: string|null}> */
    private function race(string $workspace, bool $duplicate): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real MariaDB concurrency requires pcntl_fork.');
        }
        DB::disconnect();
        $children = [];
        foreach (range(1, 4) as $number) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                $this->fail('Unable to allocate the concurrency barrier.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a concurrent admission.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                try {
                    if (fread($pair[1], 1) !== 'G') {
                        exit(3);
                    }
                    DB::purge();
                    Queue::fake([ProcessDemoAiRun::class]);
                    $identity = $duplicate ? 'same' : (string) $number;
                    $run = app(DemoAiLedger::class)->admit($workspace, hash('sha256', 'session'.$identity), hash('sha256', 'ip'.$identity), 'key'.$identity);
                    fwrite($pair[1], json_encode(['id' => $run->id, 'status' => $run->status, 'failure' => $run->failure_code], JSON_THROW_ON_ERROR)."\n");
                    fclose($pair[1]);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($pair[1], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
                    exit(2);
                }
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 10);
            $children[] = [$pid, $pair[0]];
        }
        DB::beginTransaction();
        DB::table('demo_ai_control')->where('id', 1)->lockForUpdate()->firstOrFail();
        foreach ($children as [$pid, $socket]) {
            fwrite($socket, 'G');
        }
        $read = array_column($children, 1);
        $write = $except = [];
        try {
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000), 'A contender bypassed the held MariaDB ledger lock.');
        } finally {
            DB::commit();
        }
        $outcomes = [];
        foreach ($children as [$pid, $socket]) {
            $line = fgets($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), (string) $line);
            $this->assertIsString($line);
            $outcomes[] = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
        }

        return $outcomes;
    }
}
