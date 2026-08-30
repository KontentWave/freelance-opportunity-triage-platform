<?php

namespace Tests\Unit\Infrastructure\Email;

use App\Domain\Opportunities\Enums\ContractType;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use App\Domain\Opportunities\Enums\OpportunityProvider;
use App\Domain\Opportunities\Exceptions\EmailParseException;
use App\Infrastructure\Email\UpworkJobAlertParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

final class UpworkJobAlertParserTest extends TestCase
{
    #[Test]
    public function it_parses_each_supported_hourly_fixture(): void
    {
        $parser = new UpworkJobAlertParser;

        foreach ([
            'hourly-client-success.eml',
            'hourly-operations-coordinator.eml',
            'hourly-unknown-rate.eml',
        ] as $fixture) {
            $parsed = $parser->parse($this->fixture($fixture));

            $this->assertSame(OpportunityProvider::UpworkEmail, $parsed->provider);
            $this->assertSame(ContractType::Hourly, $parsed->contractType);
            $this->assertNotSame('', $parsed->sourceMessageId);
            $this->assertMatchesRegularExpression('/^\d+$/', $parsed->externalJobId);
            $this->assertStringStartsWith('https://www.upwork.com/jobs/~', $parsed->canonicalUrl);
            $this->assertStringNotContainsString('?', $parsed->canonicalUrl);
            $this->assertStringNotContainsString('#', $parsed->canonicalUrl);
            $this->assertNotSame('', $parsed->title);
        }
    }

    #[Test]
    public function it_accepts_each_allowlisted_upwork_sender(): void
    {
        $parser = new UpworkJobAlertParser;

        foreach ([
            'donotreply@upwork.com',
            'upwork@t.upwork.com',
        ] as $sender) {
            $rawEmail = str_replace(
                'From: Upwork Notification <donotreply@upwork.com>',
                sprintf('From: Upwork Notification <%s>', $sender),
                $this->fixture('hourly-client-success.eml'),
            );

            $parsed = $parser->parse($rawEmail);

            $this->assertSame('200000000000000000001', $parsed->externalJobId);
        }
    }

    #[Test]
    public function it_converts_a_zero_rate_range_to_unknown(): void
    {
        $parser = new UpworkJobAlertParser;

        $parsed = $parser->parse($this->fixture('hourly-unknown-rate.eml'));

        $this->assertNull($parsed->hourlyMin);
        $this->assertNull($parsed->hourlyMax);
        $this->assertSame(ContractType::Hourly, $parsed->contractType);
    }

    #[Test]
    public function it_decodes_html_entities_and_normalizes_whitespace(): void
    {
        $parser = new UpworkJobAlertParser;

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertSame('Client Success & Project Manager', $parsed->title);
        $this->assertSame('Lead client onboarding & retention across multiple delivery tracks...', $parsed->excerpt);
    }

    #[Test]
    public function it_extracts_visible_skills_and_the_hidden_skill_count(): void
    {
        $parser = new UpworkJobAlertParser;

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertSame([
            'Project Management',
            'Quality Assurance',
            'Communication',
        ], $parsed->skills);
        $this->assertSame(2, $parsed->hiddenSkillCount);
    }

    #[Test]
    public function it_expands_rounded_client_spend_without_claiming_precision(): void
    {
        $parser = new UpworkJobAlertParser;

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertTrue($parsed->paymentVerified);
        $this->assertSame('4.75', $parsed->clientRating);
        $this->assertSame('79000.00', $parsed->clientSpendUsd);
        $this->assertTrue($parsed->clientSpendApproximate);
        $this->assertSame('United States', $parsed->clientCountry);
    }

    #[Test]
    public function it_infers_the_nearest_non_future_posting_date(): void
    {
        $parser = new UpworkJobAlertParser;
        $rawEmail = str_replace(
            ['Date: Wed, 27 Aug 2026 10:15:00 +0000', 'Posted on: Aug 26'],
            ['Date: Fri, 02 Jan 2026 10:15:00 +0000', 'Posted on: Dec 31'],
            $this->fixture('hourly-client-success.eml'),
        );

        $parsed = $parser->parse($rawEmail);

        $this->assertSame(
            CarbonImmutable::create(2025, 12, 31, 0, 0, 0, 'UTC')->toDateString(),
            $parsed->postedOn?->toDateString(),
        );
    }

    #[Test]
    public function it_strips_all_query_parameters_and_fragments_from_the_job_url(): void
    {
        $parser = new UpworkJobAlertParser;

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertSame('https://www.upwork.com/jobs/~200000000000000000001', $parsed->canonicalUrl);
    }

    #[Test]
    public function it_rejects_a_non_https_or_non_allowlisted_job_url(): void
    {
        $parser = new UpworkJobAlertParser;

        foreach ([
            'http://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment',
            'https://evil.example.test/jobs/~200000000000000000001?utm_source=test#fragment',
        ] as $replacement) {
            $rawEmail = str_replace(
                'https://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment',
                $replacement,
                $this->fixture('hourly-client-success.eml'),
            );

            try {
                $parser->parse($rawEmail);
                $this->fail('Expected an EmailParseException to be thrown.');
            } catch (EmailParseException $exception) {
                $this->assertSame(EmailParseErrorCode::InvalidJobUrl, $exception->errorCode);
                $this->assertSame(EmailParseErrorCode::InvalidJobUrl->value, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_rejects_a_missing_plain_text_part(): void
    {
        $parser = new UpworkJobAlertParser;
        $rawEmail = <<<'EOT'
Message-ID: <fixture-html-only@example.test>
Date: Wed, 27 Aug 2026 10:15:00 +0000
From: Upwork Notification <donotreply@upwork.com>
To: owner@example.test
Subject: New job alert: HTML Only
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="fixture-boundary-html-only"

--fixture-boundary-html-only
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<html><body>ignored</body></html>
--fixture-boundary-html-only--
EOT;

        try {
            $parser->parse($rawEmail);
            $this->fail('Expected an EmailParseException to be thrown.');
        } catch (EmailParseException $exception) {
            $this->assertSame(EmailParseErrorCode::MissingPlainText, $exception->errorCode);
            $this->assertSame(EmailParseErrorCode::MissingPlainText->value, $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_message_without_a_job_identifier(): void
    {
        $parser = new UpworkJobAlertParser;
        $rawEmail = preg_replace(
            '@https://www\.upwork\.com/jobs/~\d+(?:\?[^\s]+)?(?:#[^\s]+)?@',
            'https://www.upwork.com/nx/search/jobs/?q=operations',
            $this->fixture('hourly-client-success.eml'),
        );

        $this->assertIsString($rawEmail);

        try {
            $parser->parse($rawEmail);
            $this->fail('Expected an EmailParseException to be thrown.');
        } catch (EmailParseException $exception) {
            $this->assertSame(EmailParseErrorCode::MissingJobId, $exception->errorCode);
            $this->assertSame(EmailParseErrorCode::MissingJobId->value, $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_an_unexpected_sender(): void
    {
        $parser = new UpworkJobAlertParser;
        $rawEmail = str_replace(
            'From: Upwork Notification <donotreply@upwork.com>',
            'From: Evil Sender <evil@example.test>',
            $this->fixture('hourly-client-success.eml'),
        );

        try {
            $parser->parse($rawEmail);
            $this->fail('Expected an EmailParseException to be thrown.');
        } catch (EmailParseException $exception) {
            $this->assertSame(EmailParseErrorCode::UnsupportedSender, $exception->errorCode);
            $this->assertSame(EmailParseErrorCode::UnsupportedSender->value, $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_an_oversized_email_before_mime_parsing(): void
    {
        $mimeParser = $this->createMock(MailMimeParser::class);
        $mimeParser->expects($this->never())->method('parse');

        $parser = new UpworkJobAlertParser($mimeParser);

        $rawEmail = str_pad(
            'Message-ID: <fixture-oversized@example.test>'."\n",
            ((int) config('opportunity_sources.email_max_bytes')) + 1,
            'A'
        );

        try {
            $parser->parse($rawEmail);
            $this->fail('Expected an EmailParseException to be thrown.');
        } catch (EmailParseException $exception) {
            $this->assertSame(EmailParseErrorCode::EmailTooLarge, $exception->errorCode);
            $this->assertSame(EmailParseErrorCode::EmailTooLarge->value, $exception->getMessage());
        }
    }

    #[Test]
    public function it_returns_only_stable_error_codes(): void
    {
        $cases = [
            'mime_parse_failed' => [
                'parser' => new UpworkJobAlertParser(tap($this->createStub(MailMimeParser::class), function (MailMimeParser $mimeParser): void {
                    $mimeParser->method('parse')->willThrowException(new \RuntimeException('fixture'));
                })),
                'raw_email' => $this->fixture('hourly-client-success.eml'),
                'expected' => EmailParseErrorCode::MimeParseFailed,
            ],
            'missing_message_id' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => preg_replace('/^Message-ID:.*\R/mi', '', $this->fixture('hourly-client-success.eml')),
                'expected' => EmailParseErrorCode::MissingMessageId,
            ],
            'unsupported_sender' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => str_replace(
                    'From: Upwork Notification <donotreply@upwork.com>',
                    'From: Evil Sender <evil@example.test>',
                    $this->fixture('hourly-client-success.eml'),
                ),
                'expected' => EmailParseErrorCode::UnsupportedSender,
            ],
            'unsupported_subject' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => str_replace(
                    'Subject: New job alert: Client Success and Project Manager',
                    'Subject: Weekly digest: Client Success',
                    $this->fixture('hourly-client-success.eml'),
                ),
                'expected' => EmailParseErrorCode::UnsupportedSubject,
            ],
            'missing_plain_text' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => <<<'EOT'
Message-ID: <fixture-html-only@example.test>
Date: Wed, 27 Aug 2026 10:15:00 +0000
From: Upwork Notification <donotreply@upwork.com>
To: owner@example.test
Subject: New job alert: HTML Only
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="fixture-boundary-html-only"

--fixture-boundary-html-only
Content-Type: text/html; charset=UTF-8
Content-Transfer-Encoding: quoted-printable

<html><body>ignored</body></html>
--fixture-boundary-html-only--
EOT,
                'expected' => EmailParseErrorCode::MissingPlainText,
            ],
            'unsupported_contract_type' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => str_replace(
                    'Hourly: $40.00 - $60.00',
                    'Fixed-price: $1,500.00',
                    $this->fixture('hourly-client-success.eml'),
                ),
                'expected' => EmailParseErrorCode::UnsupportedContractType,
            ],
            'missing_job_id' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => preg_replace(
                    '@https://www\.upwork\.com/jobs/~\d+(?:\?[^\s]+)?(?:#[^\s]+)?@',
                    'https://www.upwork.com/nx/search/jobs/?q=operations',
                    $this->fixture('hourly-client-success.eml'),
                ),
                'expected' => EmailParseErrorCode::MissingJobId,
            ],
            'invalid_job_url' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => str_replace(
                    'https://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment',
                    'http://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment',
                    $this->fixture('hourly-client-success.eml'),
                ),
                'expected' => EmailParseErrorCode::InvalidJobUrl,
            ],
            'missing_title' => [
                'parser' => new UpworkJobAlertParser(tap($this->createStub(MailMimeParser::class), function (MailMimeParser $mimeParser): void {
                    $realMessage = (new MailMimeParser)->parse($this->fixture('hourly-client-success.eml'), false);
                    $message = $this->createStub(IMessage::class);

                    $message->method('getHeaderValue')->willReturnCallback(
                        static fn (string $name): mixed => $realMessage->getHeaderValue($name)
                    );
                    $message->method('getHeader')->willReturnCallback(
                        static fn (string $name): mixed => $realMessage->getHeader($name)
                    );
                    $message->method('getSubject')->willReturn($realMessage->getSubject());
                    $message->method('getTextPartCount')->willReturn(1);
                    $message->method('getTextContent')->willReturn(implode("\n\n", [
                        'https://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment',
                        'https://www.upwork.com/jobs/~200000000000000000001?frkscc=tracking-token',
                    ]));

                    $mimeParser->method('parse')->willReturn($message);
                })),
                'raw_email' => $this->fixture('hourly-client-success.eml'),
                'expected' => EmailParseErrorCode::MissingTitle,
            ],
            'malformed_terms' => [
                'parser' => new UpworkJobAlertParser,
                'raw_email' => str_replace(
                    'Hourly: $40.00 - $60.00',
                    'Hourly: TBD',
                    $this->fixture('hourly-client-success.eml'),
                ),
                'expected' => EmailParseErrorCode::MalformedTerms,
            ],
        ];

        foreach ($cases as $case) {
            $parser = $case['parser'];
            $rawEmail = $case['raw_email'];

            $this->assertIsString($rawEmail);

            try {
                $parser->parse($rawEmail);
                $this->fail('Expected an EmailParseException to be thrown.');
            } catch (EmailParseException $exception) {
                $this->assertSame($case['expected'], $exception->errorCode);
                $this->assertSame($case['expected']->value, $exception->getMessage());
                $this->assertContains($exception->getMessage(), array_map(
                    static fn (EmailParseErrorCode $errorCode): string => $errorCode->value,
                    EmailParseErrorCode::cases(),
                ));
            }
        }
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Emails/upwork/'.$name));

        $this->assertIsString($contents);

        return $contents;
    }
}
