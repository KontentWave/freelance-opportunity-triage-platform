<?php

namespace Tests\Unit\Domain\Mailbox;

use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MailboxConfigurationTest extends TestCase
{
    #[Test]
    public function it_rejects_missing_required_configuration_when_mailbox_intake_is_enabled(): void
    {
        foreach ([
            ['workspace_id' => ''],
            ['mailbox_key' => ''],
            ['mailbox_key' => str_repeat('a', 65)],
            ['host' => ''],
            ['port' => 0],
            ['port' => 65_536],
            ['username' => ''],
            ['password' => ''],
            ['folder' => ''],
            ['candidate_from' => ''],
            ['candidate_subject_prefix' => ''],
        ] as $override) {
            try {
                MailboxConfiguration::fromArray([...$this->enabledValues(), ...$override], isTesting: false);
                $this->fail('Expected a MailboxIntakeException to be thrown.');
            } catch (MailboxIntakeException $exception) {
                $this->assertSame(MailboxIntakeErrorCode::ConfigurationInvalid, $exception->errorCode);
            }
        }
    }

    #[Test]
    public function it_rejects_insecure_transport_or_disabled_certificate_validation_outside_tests(): void
    {
        foreach ([
            ['encryption' => 'none'],
            ['validate_cert' => false],
        ] as $override) {
            try {
                MailboxConfiguration::fromArray([...$this->enabledValues(), ...$override], isTesting: false);
                $this->fail('Expected a MailboxIntakeException to be thrown.');
            } catch (MailboxIntakeException $exception) {
                $this->assertSame(MailboxIntakeErrorCode::InsecureTransport, $exception->errorCode);
            }
        }
    }

    #[Test]
    public function it_clamps_batch_retry_and_lookback_limits(): void
    {
        $configuration = MailboxConfiguration::fromArray([
            ...$this->enabledValues(),
            'batch_size' => 500,
            'initial_lookback_hours' => 0,
            'max_attempts' => 10,
        ], isTesting: false);

        $this->assertSame(100, $configuration->batchSize);
        $this->assertSame(1, $configuration->initialLookbackHours);
        $this->assertSame(5, $configuration->maxAttempts);
    }

    #[Test]
    public function it_performs_no_probe_when_mailbox_intake_is_disabled(): void
    {
        config()->set('opportunity_mailbox', [
            'enabled' => false,
        ]);

        $configuration = $this->app->make(MailboxConfiguration::class);

        $this->assertFalse($configuration->enabled);
        $this->assertNull($configuration->host);
        $this->assertNull($configuration->username);
        $this->assertNull($configuration->password);
        $this->assertTrue($this->app->bound(MailboxClient::class));
        $this->assertInstanceOf(MailboxClient::class, $this->app->make(MailboxClient::class));
    }

    /** @return array<string, bool|int|string> */
    private function enabledValues(): array
    {
        return [
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
        ];
    }
}
