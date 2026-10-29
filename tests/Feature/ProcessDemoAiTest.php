<?php

namespace Tests\Feature;

use App\Application\DemoAi\DemoAiLedger;
use App\Application\DemoAi\ProcessDemoAi;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Infrastructure\Email\BoundedDemoMailbox;
use App\Infrastructure\Email\DeadlineAwareImapProtocol;
use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Jobs\ProcessDemoAiRun;
use App\Models\DemoAiAttempt;
use App\Models\DemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

final class ProcessDemoAiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_completes_an_empty_bounded_snapshot_without_provider_work(): void
    {
        $run = $this->admit();
        Http::preventStrayRequests();
        $this->mailbox([]);

        app(ProcessDemoAi::class)->execute($run->id);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->selected_count);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 0]);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_runs_real_intake_and_provider_code_with_controlled_transports_without_duplicate_paid_work(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $run = $this->admit();
        config([
            'demo_ai.counting_enabled' => true,
            'demo_ai.counting_policy_evidence_sha256' => hash('sha256', 'offline policy fixture'),
        ]);
        $raw = file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml'));
        $this->mailbox([1 => $raw]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses/input_tokens' => Http::response(['object' => 'response.input_tokens', 'input_tokens' => 1000]),
            'https://api.openai.com/v1/responses' => Http::response([
                'model' => 'gpt-6-luna', 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 1000], 'output_tokens' => 100],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'source_id' => 'DEMO-001', 'recommendation' => 'APPLY',
                    'reasons' => [['criterion_id' => 'scope_fit', 'explanation' => 'Bounded manual testing.',
                        'evidence' => [['field' => 'input.description', 'quote' => 'Manual testing only; no automation required.']]]],
                    'missing_information' => [],
                ], JSON_THROW_ON_ERROR)]]]],
            ])]);
        $process = app(ProcessDemoAi::class);

        $process->execute($run->id);
        $process->execute($run->id);
        $process->execute($run->id);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->succeeded_count);
        $this->assertDatabaseCount('demo_ai_attempts', 1);
        $this->assertDatabaseCount('opportunities', 0);
        $this->assertDatabaseHas('demo_ai_budgets', ['settled_micro_usd' => 175, 'reserved_micro_usd' => 0]);
        $attempt = DemoAiAttempt::query()->sole();
        $this->assertSame(1000, $attempt->request_provenance['input_count']['input_tokens']);
        $this->assertSame($attempt->request_provenance['request_sha256'], $attempt->request_provenance['input_count']['request_sha256']);
        $this->assertNotNull($attempt->inference_authorized_at);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    #[Test]
    public function it_releases_a_failed_count_without_inference_retry_or_uncertain_spend(): void
    {
        $run = $this->admit();
        config([
            'demo_ai.counting_enabled' => true,
            'demo_ai.counting_policy_evidence_sha256' => hash('sha256', 'offline policy fixture'),
        ]);
        $this->mailbox([1 => file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml'))]);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses/input_tokens' => Http::response([], 503)]);
        $process = app(ProcessDemoAi::class);

        $process->execute($run->id);
        $process->execute($run->id);
        $process->execute($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertDatabaseHas('demo_ai_attempts', [
            'status' => 'known', 'failure_code' => 'demo_ai.count_failed',
            'reserved_micro_usd' => 0, 'settled_micro_usd' => 0, 'inference_authorized_at' => null,
        ]);
        $this->assertDatabaseHas('demo_ai_control', ['blocked' => false, 'attempt_id' => null]);
        $this->assertDatabaseHas('demo_ai_budgets', ['settled_micro_usd' => 0, 'reserved_micro_usd' => 0]);
        $this->assertDatabaseCount('demo_ai_attempts', 1);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_reports_the_unverified_token_bound_without_paid_dispatch_or_reserved_liability(): void
    {
        $run = $this->admit();
        $this->mailbox([1 => file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml'))]);
        Http::preventStrayRequests();
        $process = app(ProcessDemoAi::class);

        $process->execute($run->id);
        $process->execute($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('demo_ai.token_bound_unverified', $run->fresh()->failure_code);
        $this->assertDatabaseCount('demo_ai_attempts', 0);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 0, 'settled_micro_usd' => 0]);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_does_not_expose_database_bindings_in_a_failed_identifier_only_job(): void
    {
        $run = $this->admit();
        $content = 'Synthetic source content must not enter the failed-job exception';
        DB::partialMock()->shouldReceive('statement')->once()
            ->with('SET SESSION innodb_lock_wait_timeout = 5')
            ->andThrow(new QueryException('mariadb', 'insert into demo_ai_items values (?)', [$content], new RuntimeException('controlled database failure')));
        try {
            (new ProcessDemoAiRun($run->id))->handle(app(ProcessDemoAi::class));
            $this->fail('The database failure was hidden.');
        } catch (DemoAiException $exception) {
            $this->assertSame('demo_ai.persistence_failed', $exception->failureCode);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($content, (string) $exception);
        }
    }

    private function admit(): DemoAiRun
    {
        $user = User::factory()->create(['workspace_id' => Workspace::factory()]);
        config([
            'opportunity_review.mode' => 'demo', 'opportunity_review.demo_user_id' => $user->id,
            'demo_ai.enabled' => true, 'demo_ai.api_key' => 'offline-test-key',
            'demo_ai.mailbox_key' => 'authored-demo', 'demo_ai.mailbox_host' => 'imap.example.invalid',
            'demo_ai.mailbox_username' => 'demo', 'demo_ai.mailbox_password' => 'offline',
            'demo_ai.mailbox_folder' => 'FOTP Demo',
        ]);
        Queue::fake([ProcessDemoAiRun::class]);
        $run = app(DemoAiLedger::class)->admit($user->workspace_id, hash('sha256', 'session'), hash('sha256', 'ip'), 'key');
        Queue::assertPushed(ProcessDemoAiRun::class, 1);

        return $run;
    }

    /** @param array<int, string> $messages */
    private function mailbox(array $messages): void
    {
        $protocol = $this->createMock(DeadlineAwareImapProtocol::class);
        $protocol->expects($this->once())->method('reset');
        $protocol->method('setConnectionTimeout')->willReturnSelf();
        $protocol->method('connect')->willReturn(true);
        $protocol->method('login')->willReturn(Response::empty()->setResult(true));
        $protocol->method('examineFolder')->willReturn(Response::empty()->setResult(['uidvalidity' => 1, 'uidnext' => count($messages) + 1]));
        $protocol->method('fetch')->willReturnCallback(function (array $fields, array $uids) use ($messages): Response {
            $uid = $uids[0];
            $values = ['UID' => $uid, 'RFC822.SIZE' => strlen($messages[$uid])];
            if (in_array('BODY.PEEK[]', $fields, true)) {
                $values['BODY[]'] = $messages[$uid];
            }

            return Response::empty()->setResult([$uid => $values]);
        });
        $this->app->instance(BoundedDemoMailbox::class, new BoundedDemoMailbox(
            app(DemoEmailNormalizer::class), app(MonotonicClock::class), fn (): ImapProtocol => $protocol,
        ));
    }
}
