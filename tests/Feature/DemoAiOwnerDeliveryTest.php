<?php

namespace Tests\Feature;

use App\Application\DemoAi\DemoAiLedger;
use App\Application\DemoAi\DemoAiReport;
use App\Application\DemoAi\OwnerReportDelivery;
use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Models\DemoAiAttempt;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;
use Throwable;

final class DemoAiOwnerDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function it_sends_the_exact_stored_escaped_report_once_and_records_receipt_separately(): void
    {
        $run = $this->report();
        $snapshot = $run->report_snapshot;
        $html = view('review.ai-report', ['report' => $snapshot])->render();
        $transport = $this->transport();

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])
            ->expectsOutputToContain('Owner delivery: accepted. Transport acceptance is not recipient receipt.')->assertSuccessful();
        $this->assertDatabaseHas('demo_ai_deliveries', ['run_id' => $run->id, 'status' => 'accepted', 'receipt_reported_at' => null]);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->assertSuccessful();
        config(['demo_ai.owner_delivery.version' => 'rotated-v2']);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->assertSuccessful();
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame($html, $email->getHtmlBody());
        $this->assertSame($html, $email->getAttachments()[0]->getBody());
        $this->assertSame('fictional-demo-'.$run->id.'.html', $email->getAttachments()[0]->getFilename());
        $this->assertSame(['owner@example.invalid'], array_map(fn ($address): string => $address->getAddress(), $email->getTo()));
        $this->assertSame([], $email->getCc());
        $this->assertSame([], $email->getBcc());
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Fictional demo alerts', $html);
        $this->assertStringContainsString('public-profile-v2', $html);
        $this->assertStringContainsString('An evaluation outcome could not be confirmed.', $html);
        $this->assertStringContainsString('MAYBE', $html);
        $this->assertStringContainsString('availability', $html);
        $this->assertSame($snapshot, $run->fresh()->report_snapshot);

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--receipt' => 'owner-confirmation-20261030'])
            ->expectsOutputToContain('receipt_operator_reported')->assertSuccessful();
        $this->assertNotNull(DB::table('demo_ai_deliveries')->value('receipt_reported_at'));
        $this->assertSame('accepted', DB::table('demo_ai_deliveries')->value('status'));
        $this->assertStringNotContainsString('owner@example.invalid', DB::table('demo_ai_deliveries')->get()->toJson());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    #[DataProvider('invalidConfiguration')]
    public function it_rejects_unverified_unallowlisted_or_disabled_recipient_configuration(string $key, mixed $value): void
    {
        $run = $this->report();
        config([$key => $value]);
        $transport = $this->transport();

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])
            ->expectsOutputToContain('demo_ai.delivery_recipient_configuration')->assertFailed();

        $this->assertCount(0, $transport->messages());
        $this->assertDatabaseCount('demo_ai_deliveries', 0);
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['demo_ai.owner_delivery.enabled', false],
            ['demo_ai.owner_delivery.recipient', null],
            ['demo_ai.owner_delivery.recipient', 'visitor@example.invalid'],
            ['demo_ai.owner_delivery.recipient', "owner@example.invalid\r\nBcc: other@example.invalid"],
            ['demo_ai.owner_delivery.allowlist_sha256', null],
            ['demo_ai.owner_delivery.version', ''],
            ['demo_ai.owner_delivery.verification_reference', null],
        ];
    }

    #[Test]
    #[DataProvider('unavailableReports')]
    public function it_refuses_missing_expired_foreign_private_or_unavailable_reports(string $case): void
    {
        $run = $this->report();
        $id = $run->id;
        match ($case) {
            'missing' => $id = '01AAAAAAAAAAAAAAAAAAAAAAAA',
            'expired' => $run->update(['expires_at' => now()]),
            'purged' => $run->update(['purged_at' => now(), 'report_snapshot' => null]),
            'unfinished' => $run->update(['status' => 'running', 'finalized_at' => null]),
            'no_snapshot' => DB::table('demo_ai_runs')->where('id', $id)->update(['report_snapshot' => null]),
            'foreign' => config(['opportunity_review.demo_user_id' => User::factory()->create()->id]),
            'private' => config(['opportunity_review.mode' => 'private']),
            'private_profile' => DB::table('demo_ai_runs')->where('id', $id)->update([
                'report_snapshot' => json_encode(array_replace_recursive($run->report_snapshot, ['approved' => ['profile_version' => 'private']]), JSON_THROW_ON_ERROR),
            ]),
            default => throw new RuntimeException('Unknown report refusal test case.'),
        };
        $transport = $this->transport();

        $this->artisan('demo-ai:deliver-owner', ['run' => $id])->assertFailed();

        $this->assertCount(0, $transport->messages());
        $this->assertDatabaseCount('demo_ai_deliveries', 0);
    }

    public static function unavailableReports(): array
    {
        return array_map(fn (string $case): array => [$case], [
            'missing', 'expired', 'purged', 'unfinished', 'no_snapshot', 'foreign', 'private', 'private_profile',
        ]);
    }

    #[Test]
    public function it_has_no_recipient_override_or_public_sending_route(): void
    {
        $run = $this->report();
        $transport = $this->transport();
        $this->withoutExceptionHandling();
        try {
            $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--to' => 'visitor@example.invalid'])->run();
            $this->fail('Recipient overrides must not be supported.');
        } catch (InvalidOptionException) {
            $this->assertDatabaseCount('demo_ai_deliveries', 0);
        }
        $this->withExceptionHandling();
        $this->postJson('/demo-ai/runs/'.$run->id.'/deliver', ['recipient' => 'visitor@example.invalid'])->assertNotFound();
        $this->assertCount(0, $transport->messages());
    }

    #[Test]
    public function it_retains_known_preparation_failure_without_retries(): void
    {
        $run = $this->report();
        config(['mail.mailers.smtp.port' => 25]);
        $transport = $this->transport();

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->expectsOutputToContain('failed')->assertFailed();
        config(['mail.mailers.smtp.port' => 587]);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->assertFailed();

        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'failed', 'sending_at' => null]);
        $this->assertCount(0, $transport->messages());
    }

    #[Test]
    public function it_retains_ambiguous_smtp_outcomes_and_all_ai_liabilities_without_blind_resending(): void
    {
        $run = $this->report();
        DemoAiAttempt::factory()->create([
            'item_id' => DemoAiItem::query()->where('run_id', $run->id)->where('status', 'uncertain')->sole()->id,
            'status' => 'uncertain',
        ]);
        DB::table('demo_ai_budgets')->insert(['day' => now('UTC')->toDateString(), 'reserved_micro_usd' => 1892]);
        $before = $this->aiState();
        $transport = $this->transport(true);

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->expectsOutputToContain('uncertain')->assertFailed();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->assertFailed();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--recover' => true])->assertFailed();

        $this->assertSame($before, $this->aiState());
        $this->assertCount(1, $transport->messages());
        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'uncertain', 'accepted_at' => null, 'receipt_reported_at' => null]);
        $this->assertStringNotContainsString('SMTP-SECRET', DB::table('demo_ai_deliveries')->get()->toJson());
        $this->travel(8)->days();
        app(DemoAiLedger::class)->recover();
        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'uncertain']);
        $this->assertDatabaseHas('demo_ai_budgets', ['reserved_micro_usd' => 1892]);
        $this->assertDatabaseHas('demo_ai_attempts', ['status' => 'uncertain', 'reserved_micro_usd' => 1892]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_recovers_interrupted_states_without_sending_or_claiming_receipt(): void
    {
        $run = $this->report();
        $transport = $this->transport();
        DB::table('demo_ai_deliveries')->insert([
            'run_id' => $run->id, 'report_sha256' => str_repeat('a', 64),
            'recipient_identity' => str_repeat('b', 64), 'status' => 'sending',
            'recipient_config_version' => 'owner-v1', 'verification_reference_sha256' => str_repeat('c', 64),
            'prepared_at' => now(), 'sending_at' => now(),
        ]);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--recover' => true])->expectsOutputToContain('sending')->assertFailed();
        $this->travel(150)->seconds();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--recover' => true])->expectsOutputToContain('uncertain')->assertFailed();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->assertFailed();
        $this->assertCount(0, $transport->messages());
        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'uncertain', 'receipt_reported_at' => null]);
        DB::table('demo_ai_deliveries')->update(['status' => 'prepared', 'sending_at' => null]);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--recover' => true])->expectsOutputToContain('failed')->assertFailed();
        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'failed']);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--receipt' => 'invalid@address'])->assertFailed();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--receipt' => 'owner-confirmed', '--recover' => true])->assertFailed();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--receipt' => 'owner-confirmed'])->assertFailed();
    }

    #[Test]
    public function it_does_not_send_before_an_outer_transaction_commits(): void
    {
        $run = $this->report();
        $transport = $this->transport();
        DB::beginTransaction();
        try {
            $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])
                ->expectsOutputToContain('demo_ai.delivery_transaction_active')->assertFailed();
            $this->assertDatabaseCount('demo_ai_deliveries', 0);
        } finally {
            DB::rollBack();
        }
        $this->assertCount(0, $transport->messages());
    }

    #[Test]
    public function it_rechecks_disable_and_recipient_identity_after_preparation_before_sending(): void
    {
        $run = $this->report();
        $transport = new ArrayTransport;
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->andReturnUsing(function () use ($transport): Mailer {
            config(['demo_ai.owner_delivery.enabled' => false]);

            return new Mailer('controlled-owner', app('view'), $transport);
        });
        $this->app->instance(MailManager::class, $manager);

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])
            ->expectsOutputToContain('demo_ai.delivery_recipient_configuration')->assertFailed();
        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'prepared', 'sending_at' => null]);
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id, '--recover' => true])->assertFailed();
        $this->assertCount(0, $transport->messages());
    }

    #[Test]
    public function it_cannot_label_a_null_transport_result_as_acceptance_or_retry_it(): void
    {
        $run = $this->report();
        $transport = new class extends ArrayTransport
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                parent::send($message, $envelope);

                return null;
            }
        };
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->andReturn(new Mailer('controlled-owner', app('view'), $transport));
        $this->app->instance(MailManager::class, $manager);

        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->expectsOutputToContain('uncertain')->assertFailed();
        $this->artisan('demo-ai:deliver-owner', ['run' => $run->id])->assertFailed();

        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'uncertain', 'accepted_at' => null]);
        $this->assertCount(1, $transport->messages());
    }

    #[Test]
    public function it_serializes_concurrent_operator_requests_before_any_transport_send(): void
    {
        $run = $this->report();
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real delivery contention requires pcntl_fork.');
        }
        DB::disconnect();
        $children = [];
        foreach (range(1, 4) as $number) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($pair);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($pair[0]);
                try {
                    if (fread($pair[1], 1) !== 'G') {
                        exit(3);
                    }
                    DB::purge();
                    $transport = $this->transport();
                    $status = app(OwnerReportDelivery::class)->send($run->id);
                    fwrite($pair[1], json_encode(['status' => $status, 'sends' => $transport->messages()->count()], JSON_THROW_ON_ERROR)."\n");
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(2);
                }
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 10);
            $children[] = [$pid, $pair[0]];
        }
        DB::beginTransaction();
        DB::table('demo_ai_runs')->where('id', $run->id)->lockForUpdate()->firstOrFail();
        foreach ($children as [$pid, $socket]) {
            fwrite($socket, 'G');
        }
        $read = array_column($children, 1);
        $write = $except = [];
        try {
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));
        } finally {
            DB::commit();
        }
        $sends = 0;
        foreach ($children as [$pid, $socket]) {
            $line = fgets($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertIsString($line);
            $outcome = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            $sends += $outcome['sends'];
        }
        $this->assertSame(1, $sends);
        $this->assertDatabaseCount('demo_ai_deliveries', 1);
        $this->assertDatabaseHas('demo_ai_deliveries', ['status' => 'accepted']);
    }

    private function report(): DemoAiRun
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        $workspace = Workspace::factory()->create();
        config([
            'opportunity_review.mode' => 'demo',
            'opportunity_review.demo_user_id' => User::factory()->create(['workspace_id' => $workspace->id])->id,
            'demo_ai.owner_delivery.enabled' => true,
            'demo_ai.owner_delivery.recipient' => 'owner@example.invalid',
            'demo_ai.owner_delivery.allowlist_sha256' => hash('sha256', 'owner@example.invalid'),
            'demo_ai.owner_delivery.version' => 'owner-v1',
            'demo_ai.owner_delivery.verification_reference' => 'owner-attestation-20261028',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.invalid', 'port' => 587],
            'mail.from.address' => 'reports@example.invalid',
        ]);
        $run = DemoAiRun::factory()->create([
            'workspace_id' => $workspace->id, 'status' => 'partial',
            'finalized_at' => now(), 'expires_at' => now()->addDays(7),
            'selected_count' => 2, 'succeeded_count' => 1, 'failed_count' => 1,
        ]);
        $item = DemoAiItem::factory()->make();
        $input = $item->input_snapshot;
        $input['title'] = '<script>tracking()</script>';
        DemoAiItem::factory()->create([
            'run_id' => $run->id, 'workspace_id' => $workspace->id, 'status' => 'uncertain',
            'input_snapshot' => $input, 'failure_code' => 'demo_ai.provider_uncertain',
        ]);
        $source = app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path('tests/Fixtures/Emails/demo/002.eml')));
        DemoAiItem::factory()->create([
            'run_id' => $run->id, 'workspace_id' => $workspace->id, 'ordinal' => 1,
            'source_id' => $source['source_id'], 'input_snapshot' => $source['input'],
            'status' => 'succeeded', 'result' => [
                'source_id' => $source['source_id'], 'recommendation' => 'MAYBE',
                'reasons' => [['criterion_id' => 'uncertainty', 'explanation' => 'Confirm availability.',
                    'evidence' => [['field' => 'input.title', 'quote' => $source['input']['title']]]]],
                'missing_information' => ['availability'],
            ],
        ]);
        $run->update(['report_snapshot' => app(DemoAiReport::class)->snapshot($run)]);

        return $run->fresh();
    }

    private function transport(bool $ambiguous = false): ArrayTransport
    {
        $transport = $ambiguous ? new class extends ArrayTransport
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                parent::send($message, $envelope);
                throw new RuntimeException('SMTP-SECRET owner@example.invalid');
            }
        } : new ArrayTransport;
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->withArgs(function (array $config): bool {
            return $config['transport'] === 'smtp' && $config['require_tls'] === true && $config['timeout'] === 10;
        })->andReturn(new Mailer('controlled-owner', app('view'), $transport));
        $this->app->instance(MailManager::class, $manager);

        return $transport;
    }

    /** @return array<string, string> */
    private function aiState(): array
    {
        $state = [];
        foreach (['demo_ai_runs', 'demo_ai_items', 'demo_ai_attempts', 'demo_ai_budgets', 'demo_ai_control', 'opportunities', 'opportunity_reviews'] as $table) {
            $state[$table] = DB::table($table)->get()->toJson();
        }

        return $state;
    }
}
