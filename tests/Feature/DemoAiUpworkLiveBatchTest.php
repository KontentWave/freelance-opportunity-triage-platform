<?php

namespace Tests\Feature;

use App\Application\DemoAi\DemoAiLedger;
use App\Application\DemoAi\DemoAiReport;
use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\EvaluationOutput;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Http\Controllers\DemoAiRunController;
use App\Infrastructure\Email\BoundedDemoMailbox;
use App\Infrastructure\Email\DeadlineAwareImapProtocol;
use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Infrastructure\Email\DemoImapProtocol;
use App\Infrastructure\Email\UpworkDemoAlertNormalizer;
use App\Jobs\ProcessDemoAiRun;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

final class DemoAiUpworkLiveBatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_parses_hourly_and_fixed_upwork_alerts_over_64kb_strips_tracking_and_deduplicates_job_ids(): void
    {
        config([
            'opportunity_review.mode' => 'demo',
            'demo_ai.source_mode' => 'upwork',
            'demo_ai.mailbox_key' => 'upwork-live',
            'demo_ai.mailbox_host' => 'imap.example.invalid',
            'demo_ai.mailbox_username' => 'demo-upwork',
            'demo_ai.mailbox_password' => 'offline',
            'demo_ai.mailbox_folder' => 'UpworkAlerts',
        ]);

        $hourly = file_get_contents(base_path('tests/Fixtures/Emails/upwork/hourly-current-template.eml'));
        $this->assertIsString($hourly);
        $fixed = str_replace(
            [
                '<fixture-hourly-current-template@example.test>',
                'Hourly: $15-$25 =C2=B7 Est. time: Less than 1 month',
                '200000000000000000004',
                '<html><body>synthetic</body></html>',
            ],
            [
                '<fixed-live-template@example.test>',
                'Fixed: $1,500.00',
                '277777777777777777777',
                '<html><body>'.str_repeat('padding ', 9000).'</body></html>',
            ],
            $hourly,
        );
        $this->assertGreaterThan(65_536, strlen($fixed));

        $duplicateSecondAlert = str_replace(
            ['<fixed-live-template@example.test>', 'Date: Tue, 01 Sep 2026 09:45:49 +0000'],
            ['<fixed-duplicate-saved-search@example.test>', 'Date: Tue, 01 Sep 2026 11:05:00 +0000'],
            $fixed,
        );
        $redirectOnly = preg_replace(
            '#https://www\.upwork\.com/jobs/~\d+[^\s]*#',
            'https://link.t.upwork.com/ls/click?upn=secret-token',
            $hourly,
        );
        $this->assertIsString($redirectOnly);

        $messages = [
            101 => $redirectOnly,
            102 => $fixed,
            103 => $duplicateSecondAlert,
            104 => $hourly,
        ];

        $protocol = $this->createMock(DeadlineAwareImapProtocol::class);
        $protocol->expects($this->atLeastOnce())->method('reset');
        $protocol->method('setConnectionTimeout')->willReturnSelf();
        $protocol->method('connect')->willReturn(true);
        $protocol->method('login')->willReturn($this->imapResponse([]));
        $protocol->method('examineFolder')->willReturn($this->imapResponse(['uidvalidity' => 42, 'uidnext' => 105]));
        $protocol->method('fetch')->willReturnCallback(function (array $fields, array $ids) use ($messages): Response {
            $uid = $ids[0];
            if (! isset($messages[$uid])) {
                return $this->imapResponse([]);
            }
            if (! in_array('BODY.PEEK[]', $fields, true)) {
                return $this->imapResponse([$uid => ['UID' => $uid, 'RFC822.SIZE' => strlen($messages[$uid])]]);
            }

            return $this->imapResponse([$uid => ['UID' => $uid, 'BODY[]' => $messages[$uid]]]);
        });

        $mailbox = new BoundedDemoMailbox(
            app(DemoEmailNormalizer::class),
            app(MonotonicClock::class),
            fn (): ImapProtocol => $protocol,
            app(UpworkDemoAlertNormalizer::class),
        );
        $discovered = $mailbox->discover();

        $this->assertSame(1, $discovered['rejected']);
        $this->assertSame(1, $discovered['duplicates']);
        $this->assertCount(2, $discovered['sources']);
        $this->assertSame('UPW-~200000000000000000004', $discovered['sources'][0]['source_id']);
        $this->assertSame('hourly', $discovered['sources'][0]['input']['contract_type']);
        $this->assertSame(15.0, $discovered['sources'][0]['input']['hourly_min_usd']);
        $this->assertSame(25.0, $discovered['sources'][0]['input']['hourly_max_usd']);
        $this->assertSame('UPW-~277777777777777777777', $discovered['sources'][1]['source_id']);
        $this->assertSame('fixed', $discovered['sources'][1]['input']['contract_type']);
        $this->assertSame(1500.0, $discovered['sources'][1]['input']['fixed_budget_usd']);
        $this->assertNotEmpty($discovered['sources'][1]['input']['description']);
        $this->assertStringNotContainsString('https://', (string) $discovered['sources'][1]['input']['description']);
        $this->assertStringNotContainsString('utm_', json_encode($discovered, JSON_THROW_ON_ERROR));
        $this->assertSame(
            'https://www.upwork.com/jobs/~277777777777777777777',
            DemoAiReport::canonicalJobUrl($discovered['sources'][1]['source_namespace'], $discovered['sources'][1]['source_id']),
        );
    }

    #[Test]
    public function it_raises_demo_imap_protocol_command_byte_ceiling_for_multipart_upwork_messages(): void
    {
        $protocol = new DemoImapProtocol(Config::make(['options' => ['debug' => false, 'uid_cache' => false]]), true, 'ssl');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$client, $server] = $sockets;
        fwrite($server, str_repeat('a', 95_100)."\r\n");
        fclose($server);

        $reflection = new \ReflectionProperty(ImapProtocol::class, 'stream');
        $reflection->setValue($protocol, $client);
        $protocol->useByteLimits(18_000_000, 1_100_000);
        $protocol->useDeadline(app(MonotonicClock::class), app(MonotonicClock::class)->now() + 10.0);

        $response = Response::empty();
        $line = $protocol->nextLine($response);
        fclose($client);
        $reflection->setValue($protocol, false);

        $this->assertSame(95_102, strlen($line));
    }

    #[Test]
    public function it_validates_apply_and_skip_for_upwork_excerpts_while_requiring_exact_substring_quotes(): void
    {
        config(['demo_ai.source_mode' => 'upwork']);
        $raw = (string) file_get_contents(base_path('tests/Fixtures/Emails/upwork/hourly-current-template.eml'));
        $parsed = app(UpworkDemoAlertNormalizer::class)->parse($raw);
        $approved = app(ApprovedDemoInputs::class)->snapshot();
        $validator = app(EvaluationOutput::class);

        $applyPayload = [
            'source_id' => $parsed['source_id'],
            'recommendation' => 'APPLY',
            'reasons' => [[
                'criterion_id' => 'delivery_fit',
                'explanation' => 'Matches QA testing focus cleanly.',
                'evidence' => [
                    ['field' => 'input.title', 'quote' => 'Synthetic QA Role'],
                    ['field' => 'profile.capabilities', 'quote' => 'manual/exploratory testing'],
                ],
            ]],
            'missing_information' => [],
        ];
        $validator->validate($applyPayload, $parsed['input'], $approved);

        $skipPayload = [
            'source_id' => $parsed['source_id'],
            'recommendation' => 'SKIP',
            'reasons' => [[
                'criterion_id' => 'scope_risk',
                'explanation' => 'Outside preferred engagement structure.',
                'evidence' => [['field' => 'input.description', 'quote' => 'Test a synthetic application']],
            ]],
            'missing_information' => [],
        ];
        $validator->validate($skipPayload, $parsed['input'], $approved);

        $this->expectException(DemoAiException::class);
        $this->expectExceptionMessage('demo_ai.evidence_invalid');
        $badQuote = $applyPayload;
        $badQuote['reasons'][0]['evidence'][0]['quote'] = 'Invented quote absent from input';
        $validator->validate($badQuote, $parsed['input'], $approved);
    }

    #[Test]
    public function it_enforces_cookie_and_ip_cooldown_shares_latest_upwork_batch_and_reuses_unchanged_items(): void
    {
        $user = $this->configureUpworkDemo();
        Queue::fake([ProcessDemoAiRun::class]);

        $keyA = $this->getJson('/demo-ai/admission-key')
            ->assertOk()
            ->assertJsonPath('source_mode', 'upwork')
            ->assertJsonPath('run', null)
            ->json('idempotency_key');

        $admissionResponse = $this->postJson('/demo-ai/runs', ['idempotency_key' => $keyA])
            ->assertAccepted()
            ->assertCookie(DemoAiRunController::COOLDOWN_COOKIE);
        $firstRunId = (string) $admissionResponse->json('id');
        Queue::assertPushed(ProcessDemoAiRun::class, 1);

        $rawA = (string) file_get_contents(base_path('tests/Fixtures/Emails/upwork/hourly-current-template.eml'));
        $sourceA = app(UpworkDemoAlertNormalizer::class)->parse($rawA) + [
            'uid' => 501,
            'uidvalidity' => 9,
            'mailbox_key' => 'upwork-live',
        ];
        $ledger = app(DemoAiLedger::class);
        $startedFirst = $ledger->start($firstRunId);
        $this->assertNotNull($startedFirst);
        $ledger->select($firstRunId, [
            'mailbox_key' => 'upwork-live', 'uidvalidity' => 9, 'uidnext' => 502,
            'scanned' => 1, 'bodies' => 1, 'bytes' => strlen($rawA), 'duplicates' => 0, 'rejected' => 0,
            'sources' => [$sourceA],
        ], (string) $startedFirst->discovery_token);

        DemoAiItem::query()->where('run_id', $firstRunId)->update([
            'status' => 'succeeded',
            'result' => [
                'source_id' => $sourceA['source_id'],
                'recommendation' => 'APPLY',
                'reasons' => [[
                    'criterion_id' => 'delivery_fit',
                    'explanation' => 'Clear QA testing match.',
                    'evidence' => [['field' => 'input.title', 'quote' => 'Synthetic QA Role']],
                ]],
                'missing_information' => [],
            ],
        ]);
        $firstRun = DemoAiRun::query()->findOrFail($firstRunId);
        $firstRun->update([
            'status' => 'completed',
            'succeeded_count' => 1,
            'failed_count' => 0,
            'reserved_micro_usd' => 0,
            'finalized_at' => now('UTC'),
            'expires_at' => now('UTC')->addDays(7),
        ]);
        $firstRun->update(['report_snapshot' => app(DemoAiReport::class)->snapshot($firstRun)]);
        DB::table('demo_ai_budgets')->update(['reserved_micro_usd' => 0]);

        // Fresh visitor session immediately sees the latest evaluated Upwork batch, canonical URL, and report
        $this->flushSession();
        $this->actingAs($user);
        $visitorKey = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44'])
            ->getJson('/demo-ai/admission-key')
            ->assertOk()
            ->assertJsonPath('reused_batch', true)
            ->assertJsonPath('run.id', $firstRunId)
            ->assertJsonPath('run.items.0.canonical_url', 'https://www.upwork.com/jobs/~200000000000000000004')
            ->json('idempotency_key');

        $this->get('/demo-ai/runs/'.$firstRunId.'/report')
            ->assertOk()
            ->assertSee("Upwork job alerts evaluated against Marcel's public profile", false)
            ->assertSee('https://www.upwork.com/jobs/~200000000000000000004');
        $this->get('/demo-ai/runs/'.$firstRunId.'/report.html')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="upwork-demo-'.$firstRunId.'.html"');

        // Visitor carrying the 30-min cooldown cookie (even from a new IP) reuses the batch without admitting a new run
        $cooldownPayload = json_encode(['until' => now()->getTimestamp() + 1200], JSON_THROW_ON_ERROR);
        $this->withCredentials()
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->withCookie(DemoAiRunController::COOLDOWN_COOKIE, $cooldownPayload)
            ->postJson('/demo-ai/runs', ['idempotency_key' => $visitorKey])
            ->assertAccepted()
            ->assertJsonPath('id', $firstRunId)
            ->assertJsonPath('reused_batch', true);
        $this->assertDatabaseCount('demo_ai_runs', 1);

        // After 31 minutes, a new batch with the same unchanged alert reuses the validated result and finalizes immediately with $0 reserved
        $this->travel(31)->minutes();
        $this->flushSession();
        $this->actingAs($user);
        $afterCooldownKey = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.88'])
            ->postJson('/demo-ai/admission-key')
            ->assertOk()
            ->json('idempotency_key');
        $secondRunId = (string) $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.88'])
            ->postJson('/demo-ai/runs', ['idempotency_key' => $afterCooldownKey])
            ->assertAccepted()
            ->json('id');
        $this->assertNotSame($firstRunId, $secondRunId);

        $startedSecond = $ledger->start($secondRunId);
        $this->assertNotNull($startedSecond);
        $ledger->select($secondRunId, [
            'mailbox_key' => 'upwork-live', 'uidvalidity' => 9, 'uidnext' => 502,
            'scanned' => 1, 'bodies' => 1, 'bytes' => strlen($rawA), 'duplicates' => 0, 'rejected' => 0,
            'sources' => [$sourceA],
        ], (string) $startedSecond->discovery_token);

        $secondRun = DemoAiRun::query()->findOrFail($secondRunId);
        $this->assertSame('completed', $secondRun->status);
        $this->assertSame(1, $secondRun->succeeded_count);
        $this->assertSame(0, $secondRun->reserved_micro_usd);
        $this->assertNotNull($secondRun->report_snapshot);
    }

    private function configureUpworkDemo(): User
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['id' => 41001, 'workspace_id' => $workspace->id]);
        config([
            'opportunity_review.mode' => 'demo',
            'opportunity_review.demo_user_id' => (string) $user->id,
            'demo_ai.enabled' => true,
            'demo_ai.source_mode' => 'upwork',
            'demo_ai.cooldown_minutes' => 30,
        ]);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<mixed> $data */
    private function imapResponse(array $data): Response
    {
        return Response::empty()->setResult($data)->setCanBeEmpty($data === []);
    }
}
