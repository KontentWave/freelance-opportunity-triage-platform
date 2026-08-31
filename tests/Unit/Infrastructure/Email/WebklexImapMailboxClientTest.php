<?php

namespace Tests\Unit\Infrastructure\Email;

use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Data\MailboxCursor;
use App\Domain\Mailbox\Data\MailboxMessageReference;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use App\Infrastructure\Email\WebklexImapMailboxClient;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

final class WebklexImapMailboxClientTest extends TestCase
{
    #[Test]
    public function it_uses_uid_sequence_peek_fetching_and_certificate_validation(): void
    {
        $protocol = $this->readyProtocol();
        $factoryArguments = null;
        $protocol->expects($this->once())->method('disableDebug')->willReturnSelf();
        $protocol->expects($this->once())->method('disableUidCache')->willReturnSelf();
        $protocol->expects($this->once())->method('examineFolder')
            ->with('Opportunity Alerts')
            ->willReturn($this->response(['uidvalidity' => 9001]));
        $protocol->expects($this->once())->method('fetch')
            ->with(
                $this->callback(fn (array $items): bool => in_array('BODY.PEEK[]', $items, true)),
                [101],
                null,
                IMAP::ST_UID,
            )
            ->willReturn($this->response([
                101 => ['UID' => 101, 'RFC822.SIZE' => 72, 'BODY[]' => $this->rawMessage()],
            ]));

        $client = new WebklexImapMailboxClient(
            $this->configuration(['encryption' => 'tls']),
            function (bool $validateCert, string $encryption) use ($protocol, &$factoryArguments): ImapProtocol {
                $factoryArguments = [$validateCert, $encryption];

                return $protocol;
            },
        );

        $rawMessage = $client->fetchRaw(new MailboxMessageReference(101, 72), 1_048_576);

        $this->assertSame([true, 'starttls'], $factoryArguments);
        $this->assertSame($this->rawMessage(), $rawMessage);
    }

    #[Test]
    public function it_discovers_only_matching_candidate_envelopes_in_ascending_uid_order(): void
    {
        $protocol = $this->readyProtocol();
        $protocol->method('examineFolder')->willReturn($this->response(['uidvalidity' => 9001]));
        $protocol->expects($this->once())->method('search')
            ->with(
                $this->callback(function (array $criteria): bool {
                    $query = $criteria[0] ?? '';

                    return str_contains($query, 'FROM "alerts@example.test"')
                        && str_contains($query, 'SUBJECT "New job alert:"')
                        && str_contains($query, 'UID 101:*');
                }),
                IMAP::ST_UID,
            )
            ->willReturn($this->response([105, 103, 104]));
        $protocol->expects($this->once())->method('fetch')
            ->with(['UID', 'RFC822.SIZE'], [103, 104], null, IMAP::ST_UID)
            ->willReturn($this->response([
                104 => ['UID' => 104, 'RFC822.SIZE' => 204],
                103 => ['UID' => 103, 'RFC822.SIZE' => 203],
            ]));

        $batch = $this->client($protocol)->discover(
            new MailboxCursor(9001, 100, CarbonImmutable::parse('2026-08-29 00:00:00 UTC')),
            2,
        );

        $this->assertSame(9001, $batch->uidValidity);
        $this->assertSame([103, 104], array_map(
            static fn (MailboxMessageReference $message): int => $message->uid,
            $batch->messages,
        ));
        $this->assertSame(104, $batch->highestDiscoveredUid);
    }

    #[Test]
    public function it_discovers_candidate_envelopes_from_each_allowlisted_sender(): void
    {
        $protocol = $this->readyProtocol();
        $protocol->method('examineFolder')->willReturn($this->response(['uidvalidity' => 9001]));
        $protocol->expects($this->once())->method('search')
            ->with(
                $this->callback(function (array $criteria): bool {
                    $query = $criteria[0] ?? '';

                    return str_contains(
                        $query,
                        'OR FROM "upwork@t.upwork.com" FROM "donotreply@upwork.com"',
                    );
                }),
                IMAP::ST_UID,
            )
            ->willReturn($this->response([]));

        $client = new WebklexImapMailboxClient(
            $this->configuration([
                'candidate_from' => 'upwork@t.upwork.com,donotreply@upwork.com',
            ]),
            static fn (bool $validateCert, string $encryption): ImapProtocol => $protocol,
        );

        $client->discover(
            new MailboxCursor(9001, 100, CarbonImmutable::parse('2026-08-29 00:00:00 UTC')),
            25,
        );
    }

    #[Test]
    public function it_uses_a_bounded_lookback_after_uidvalidity_changes(): void
    {
        $protocol = $this->readyProtocol();
        $protocol->method('examineFolder')->willReturn($this->response(['uidvalidity' => 9002]));
        $protocol->expects($this->once())->method('search')
            ->with(
                $this->callback(function (array $criteria): bool {
                    $query = $criteria[0] ?? '';

                    return str_contains($query, 'SINCE 29-Aug-2026')
                        && ! str_contains($query, 'UID 501:*');
                }),
                IMAP::ST_UID,
            )
            ->willReturn($this->response([]));
        $protocol->expects($this->never())->method('fetch');

        $batch = $this->client($protocol)->discover(
            new MailboxCursor(9001, 500, CarbonImmutable::parse('2026-08-29 00:00:00 UTC')),
            25,
        );

        $this->assertSame(9002, $batch->uidValidity);
        $this->assertSame([], $batch->messages);
        $this->assertSame(0, $batch->highestDiscoveredUid);
    }

    #[Test]
    public function it_returns_complete_raw_rfc822_bytes(): void
    {
        $protocol = $this->readyProtocol();
        $protocol->method('examineFolder')->willReturn($this->response(['uidvalidity' => 9001]));
        $protocol->method('fetch')->willReturn($this->response([
            101 => ['UID' => 101, 'RFC822.SIZE' => 72, 'BODY[]' => $this->rawMessage()],
        ]));

        $rawMessage = $this->client($protocol)->fetchRaw(
            new MailboxMessageReference(101, 72),
            1_048_576,
        );

        $this->assertStringContainsString("Message-ID: <synthetic@example.test>\r\n", $rawMessage);
        $this->assertStringEndsWith("synthetic body\r\n", $rawMessage);
    }

    #[Test]
    public function it_rejects_an_oversized_message_before_fetching_its_body(): void
    {
        $protocolFactoryCalled = false;
        $client = new WebklexImapMailboxClient(
            $this->configuration(),
            function (bool $validateCert, string $encryption) use (&$protocolFactoryCalled): ImapProtocol {
                $protocolFactoryCalled = true;

                return $this->createMock(ImapProtocol::class);
            },
        );

        try {
            $client->fetchRaw(new MailboxMessageReference(101, 1_048_577), 1_048_576);
            $this->fail('Expected a MailboxIntakeException to be thrown.');
        } catch (MailboxIntakeException $exception) {
            $this->assertSame(MailboxIntakeErrorCode::MessageTooLarge, $exception->errorCode);
            $this->assertFalse($protocolFactoryCalled);
        }
    }

    #[Test]
    public function it_translates_authentication_connection_and_folder_errors_to_stable_codes(): void
    {
        foreach ([
            'connection' => MailboxIntakeErrorCode::ConnectionFailed,
            'authentication' => MailboxIntakeErrorCode::AuthenticationFailed,
            'folder' => MailboxIntakeErrorCode::FolderUnavailable,
        ] as $failure => $expectedCode) {
            $protocol = $this->createMock(ImapProtocol::class);
            $protocol->method('disableDebug')->willReturnSelf();
            $protocol->method('disableUidCache')->willReturnSelf();
            $protocol->expects($this->once())->method('logout')->willReturn($this->response(true));

            if ($failure === 'connection') {
                $protocol->expects($this->once())->method('connect')
                    ->willThrowException(new \RuntimeException('synthetic connection detail'));
            } else {
                $protocol->expects($this->once())->method('connect')->willReturn(true);
                if ($failure === 'authentication') {
                    $protocol->method('login')->willThrowException(new \RuntimeException('synthetic authentication detail'));
                } else {
                    $protocol->method('login')->willReturn($this->response(true));
                    $protocol->method('examineFolder')->willThrowException(new \RuntimeException('synthetic folder detail'));
                }
            }

            try {
                $this->client($protocol)->probe();
                $this->fail('Expected a MailboxIntakeException to be thrown.');
            } catch (MailboxIntakeException $exception) {
                $this->assertSame($expectedCode, $exception->errorCode);
                $this->assertSame($expectedCode->value, $exception->getMessage());
                $this->assertStringNotContainsString('synthetic', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_never_enables_protocol_debug_logging_or_writes_message_flags(): void
    {
        $protocol = $this->readyProtocol();
        $protocol->expects($this->never())->method('enableDebug');
        $protocol->expects($this->never())->method('selectFolder');
        $protocol->expects($this->never())->method('store');
        $protocol->method('examineFolder')->willReturn($this->response(['uidvalidity' => 9001]));
        $protocol->method('fetch')->willReturn($this->response([
            101 => ['UID' => 101, 'RFC822.SIZE' => 72, 'BODY[]' => $this->rawMessage()],
        ]));

        $client = $this->client($protocol);
        $client->fetchRaw(new MailboxMessageReference(101, 72), 1_048_576);
        $client->close();

        $this->addToAssertionCount(1);
    }

    private function client(ImapProtocol $protocol): WebklexImapMailboxClient
    {
        return new WebklexImapMailboxClient(
            $this->configuration(),
            static fn (bool $validateCert, string $encryption): ImapProtocol => $protocol,
        );
    }

    /** @return ImapProtocol&MockObject */
    private function readyProtocol(): ImapProtocol
    {
        $protocol = $this->createMock(ImapProtocol::class);
        $protocol->expects($this->once())->method('connect')->willReturn(true);
        $protocol->method('login')->willReturn($this->response(true));
        $protocol->method('connected')->willReturn(true);
        $protocol->method('logout')->willReturn($this->response(true));

        return $protocol;
    }

    /** @param array<string, mixed> $override */
    private function configuration(array $override = []): MailboxConfiguration
    {
        return MailboxConfiguration::fromArray([
            'enabled' => true,
            'workspace_id' => '01K3MEXAMPLEULID1234567890',
            'mailbox_key' => 'primary',
            'host' => 'imap.example.test',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => 'mailbox@example.test',
            'password' => 'synthetic-secret',
            'folder' => 'Opportunity Alerts',
            'candidate_from' => 'alerts@example.test',
            'candidate_subject_prefix' => 'New job alert:',
            'batch_size' => 25,
            'initial_lookback_hours' => 24,
            'max_attempts' => 3,
            'health_max_age_minutes' => 15,
            ...$override,
        ], isTesting: false);
    }

    private function response(mixed $result): Response
    {
        return Response::empty()->setResult($result)->setCanBeEmpty($result === []);
    }

    private function rawMessage(): string
    {
        return "Message-ID: <synthetic@example.test>\r\n"
            ."Subject: Synthetic\r\n"
            ."\r\n"
            ."synthetic body\r\n";
    }
}
