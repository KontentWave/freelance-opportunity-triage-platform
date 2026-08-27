<?php

namespace Tests\Unit\Infrastructure\Email;

use App\Domain\Opportunities\Enums\ContractType;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use App\Domain\Opportunities\Enums\OpportunityProvider;
use App\Domain\Opportunities\Exceptions\EmailParseException;
use App\Infrastructure\Email\UpworkJobAlertParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UpworkJobAlertParserTest extends TestCase
{
    #[Test]
    public function it_parses_each_supported_hourly_fixture(): void
    {
        $parser = new UpworkJobAlertParser();

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
    public function it_converts_a_zero_rate_range_to_unknown(): void
    {
        $parser = new UpworkJobAlertParser();

        $parsed = $parser->parse($this->fixture('hourly-unknown-rate.eml'));

        $this->assertNull($parsed->hourlyMin);
        $this->assertNull($parsed->hourlyMax);
        $this->assertSame(ContractType::Hourly, $parsed->contractType);
    }

    #[Test]
    public function it_decodes_html_entities_and_normalizes_whitespace(): void
    {
        $parser = new UpworkJobAlertParser();

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertSame('Client Success & Project Manager', $parsed->title);
        $this->assertSame('Lead client onboarding & retention across multiple delivery tracks...', $parsed->excerpt);
    }

    #[Test]
    public function it_extracts_visible_skills_and_the_hidden_skill_count(): void
    {
        $parser = new UpworkJobAlertParser();

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertSame([
            'Project Management',
            'Quality Assurance',
            'Communication',
        ], $parsed->skills);
        $this->assertSame(2, $parsed->hiddenSkillCount);
    }

    #[Test]
    public function it_strips_all_query_parameters_and_fragments_from_the_job_url(): void
    {
        $parser = new UpworkJobAlertParser();

        $parsed = $parser->parse($this->fixture('hourly-client-success.eml'));

        $this->assertSame('https://www.upwork.com/jobs/~200000000000000000001', $parsed->canonicalUrl);
    }

    #[Test]
    public function it_rejects_a_non_https_or_non_allowlisted_job_url(): void
    {
        $parser = new UpworkJobAlertParser();

        foreach ([
            'https://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment' => 'http://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment',
            'https://www.upwork.com/jobs/~200000000000000000001?utm_source=test#fragment' => 'https://evil.example.test/jobs/~200000000000000000001?utm_source=test#fragment',
        ] as $search => $replacement) {
            $rawEmail = str_replace($search, $replacement, $this->fixture('hourly-client-success.eml'));

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
        $parser = new UpworkJobAlertParser();
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
        $parser = new UpworkJobAlertParser();
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
        $parser = new UpworkJobAlertParser();
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

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Emails/upwork/' . $name));

        $this->assertIsString($contents);

        return $contents;
    }
}
