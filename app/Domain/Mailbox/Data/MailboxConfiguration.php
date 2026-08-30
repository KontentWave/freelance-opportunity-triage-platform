<?php

namespace App\Domain\Mailbox\Data;

use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;

final readonly class MailboxConfiguration
{
    public function __construct(
        public bool $enabled,
        public ?string $workspaceId,
        public string $mailboxKey,
        public ?string $host,
        public int $port,
        public string $encryption,
        public bool $validateCert,
        public ?string $username,
        public ?string $password,
        public ?string $folder,
        public string $candidateFrom,
        public string $candidateSubjectPrefix,
        public int $batchSize,
        public int $initialLookbackHours,
        public int $maxAttempts,
        public int $healthMaxAgeMinutes,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values, bool $isTesting): self
    {
        $enabled = filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $validateCert = filter_var($values['validate_cert'] ?? true, FILTER_VALIDATE_BOOL);
        $encryption = strtolower((string) ($values['encryption'] ?? 'ssl'));

        if (! $isTesting && ($validateCert !== true || ! in_array($encryption, ['ssl', 'tls'], true))) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::InsecureTransport);
        }

        $configuration = new self(
            enabled: $enabled,
            workspaceId: self::nullableString($values['workspace_id'] ?? null),
            mailboxKey: trim((string) ($values['mailbox_key'] ?? 'primary')),
            host: self::nullableString($values['host'] ?? null),
            port: (int) ($values['port'] ?? 993),
            encryption: $encryption,
            validateCert: $validateCert,
            username: self::nullableString($values['username'] ?? null),
            password: self::nullableString($values['password'] ?? null),
            folder: self::nullableString($values['folder'] ?? null),
            candidateFrom: trim((string) ($values['candidate_from'] ?? 'upwork@t.upwork.com')),
            candidateSubjectPrefix: trim((string) ($values['candidate_subject_prefix'] ?? 'New job alert:')),
            batchSize: self::clamp($values['batch_size'] ?? 25, 1, 100),
            initialLookbackHours: self::clamp($values['initial_lookback_hours'] ?? 24, 1, 168),
            maxAttempts: self::clamp($values['max_attempts'] ?? 3, 1, 5),
            healthMaxAgeMinutes: max(1, (int) ($values['health_max_age_minutes'] ?? 15)),
        );

        if ($enabled) {
            $configuration->assertEnabledConfigurationIsValid();
        }

        return $configuration;
    }

    private function assertEnabledConfigurationIsValid(): void
    {
        if ($this->workspaceId === null
            || $this->mailboxKey === ''
            || mb_strlen($this->mailboxKey) > 64
            || $this->host === null
            || $this->port < 1
            || $this->port > 65_535
            || $this->username === null
            || $this->password === null
            || $this->folder === null
            || $this->candidateFrom === ''
            || $this->candidateSubjectPrefix === '') {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::ConfigurationInvalid);
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function clamp(mixed $value, int $minimum, int $maximum): int
    {
        return min($maximum, max($minimum, (int) $value));
    }
}
