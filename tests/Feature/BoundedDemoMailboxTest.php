<?php

namespace Tests\Feature;

use App\Domain\DemoAi\DemoAiException;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Infrastructure\Email\BoundedDemoMailbox;
use App\Infrastructure\Email\DeadlineAwareImapProtocol;
use App\Infrastructure\Email\DemoEmailNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

final class BoundedDemoMailboxTest extends TestCase
{
    #[Test]
    public function it_scans_only_the_last_100_numeric_uids_descending_with_peek_and_deduplication(): void
    {
        $this->configure();
        $raw = file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml'));
        $protocol = $this->createMock(DeadlineAwareImapProtocol::class);
        $protocol->expects($this->once())->method('reset');
        $protocol->method('setConnectionTimeout')->willReturnSelf();
        $protocol->method('connect')->willReturn(true);
        $protocol->method('login')->willReturn($this->response([]));
        $protocol->expects($this->exactly(2))->method('examineFolder')->with('FOTP Demo')->willReturn($this->response(['uidvalidity' => 77, 'uidnext' => 1001]));
        $protocol->expects($this->never())->method('search');
        $uids = [];
        $protocol->method('fetch')->willReturnCallback(function (array $fields, array $ids, mixed $to, int $mode) use ($raw, &$uids): Response {
            $this->assertSame(IMAP::ST_UID, $mode);
            $uid = $ids[0];
            if (! in_array('BODY.PEEK[]', $fields, true)) {
                $uids[] = $uid;

                return $this->response($uid >= 999 ? [$uid => ['UID' => $uid, 'RFC822.SIZE' => strlen($raw)]] : []);
            }

            return $this->response([$uid => ['UID' => $uid, 'BODY[]' => $raw]]);
        });

        $result = (new BoundedDemoMailbox(app(DemoEmailNormalizer::class), app(MonotonicClock::class), fn (): ImapProtocol => $protocol))->discover();

        $this->assertSame(range(1000, 901), $uids);
        $this->assertSame(100, $result['scanned']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame(1000, $result['sources'][0]['uid']);
    }

    #[Test]
    public function it_invalidates_selection_after_uidvalidity_changes(): void
    {
        $this->configure();
        $protocol = $this->createMock(DeadlineAwareImapProtocol::class);
        $protocol->expects($this->once())->method('reset');
        $protocol->method('setConnectionTimeout')->willReturnSelf();
        $protocol->method('connect')->willReturn(true);
        $protocol->method('login')->willReturn($this->response([]));
        $protocol->method('examineFolder')->willReturnOnConsecutiveCalls(
            $this->response(['uidvalidity' => 1, 'uidnext' => 1]),
            $this->response(['uidvalidity' => 2, 'uidnext' => 1]),
        );
        $this->expectException(DemoAiException::class);
        $this->expectExceptionMessage('demo_ai.uidvalidity_changed');

        (new BoundedDemoMailbox(app(DemoEmailNormalizer::class), app(MonotonicClock::class), fn (): ImapProtocol => $protocol))->discover();
    }

    private function configure(): void
    {
        config(['opportunity_review.mode' => 'demo', 'demo_ai.mailbox_key' => 'authored', 'demo_ai.mailbox_host' => 'imap.example.invalid', 'demo_ai.mailbox_username' => 'demo', 'demo_ai.mailbox_password' => 'offline', 'demo_ai.mailbox_folder' => 'FOTP Demo']);
    }

    #[Test]
    public function it_selects_all_20_approved_sources_in_descending_arrival_order(): void
    {
        $messages = [];
        foreach (range(1, 20) as $uid) {
            $messages[$uid] = file_get_contents(base_path(sprintf('tests/Fixtures/Emails/demo/%03d.eml', $uid)));
        }
        $result = $this->discoverMessages($messages);

        $this->assertSame(20, $result['bodies']);
        $this->assertSame(20, $result['scanned']);
        $this->assertSame(0, $result['rejected']);
        $this->assertSame(range(20, 1), array_column($result['sources'], 'uid'));
    }

    #[Test]
    public function it_skips_oversize_metadata_without_fetching_the_body_and_counts_malformed_rejections(): void
    {
        $result = $this->discoverMessages([
            1 => file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml')),
            2 => file_get_contents(base_path('tests/Fixtures/Emails/demo/adversarial/invalid-json.eml')),
            3 => str_repeat('x', 65_537),
        ]);

        $this->assertSame(2, $result['bodies']);
        $this->assertSame(2, $result['rejected']);
        $this->assertCount(1, $result['sources']);
    }

    /** @param array<int, string> $messages
     * @return array<string, mixed>
     */
    private function discoverMessages(array $messages): array
    {
        $this->configure();
        $protocol = $this->createMock(DeadlineAwareImapProtocol::class);
        $protocol->expects($this->once())->method('reset');
        $protocol->method('setConnectionTimeout')->willReturnSelf();
        $protocol->method('connect')->willReturn(true);
        $protocol->method('login')->willReturn($this->response([]));
        $protocol->method('examineFolder')->willReturn($this->response(['uidvalidity' => 1, 'uidnext' => count($messages) + 1]));
        $protocol->method('fetch')->willReturnCallback(function (array $fields, array $uids) use ($messages): Response {
            $uid = $uids[0];
            $values = ['UID' => $uid, 'RFC822.SIZE' => strlen($messages[$uid])];
            if (in_array('BODY.PEEK[]', $fields, true)) {
                $this->assertLessThanOrEqual(65_536, strlen($messages[$uid]), 'An oversized body was requested.');
                $values['BODY[]'] = $messages[$uid];
            }

            return $this->response([$uid => $values]);
        });

        return (new BoundedDemoMailbox(app(DemoEmailNormalizer::class), app(MonotonicClock::class), fn (): ImapProtocol => $protocol))->discover();
    }

    #[Test]
    public function it_surfaces_the_shared_transport_deadline_as_a_safe_demo_timeout_and_resets_the_socket(): void
    {
        $this->configure();
        $protocol = $this->createMock(DeadlineAwareImapProtocol::class);
        $protocol->expects($this->once())->method('reset');
        $protocol->method('setConnectionTimeout')->willReturnSelf();
        $protocol->method('connect')->willThrowException(new MailboxIntakeException(MailboxIntakeErrorCode::PollBudgetExhausted));
        $this->expectExceptionMessage('demo_ai.intake_timeout');

        (new BoundedDemoMailbox(app(DemoEmailNormalizer::class), app(MonotonicClock::class), fn (): ImapProtocol => $protocol))->discover();
    }

    /** @param array<mixed> $data */
    private function response(array $data): Response
    {
        return Response::empty()->setResult($data)->setCanBeEmpty($data === []);
    }
}
