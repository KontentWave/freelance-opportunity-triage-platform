<?php

namespace App\Application\Mailbox\Data;

use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Enums\MailboxRunStatus;

final readonly class MailboxRunResult
{
    public function __construct(
        public MailboxRunStatus $status,
        public int $discoveredCount,
        public int $processedCount,
        public int $importedCount,
        public int $updatedCount,
        public int $duplicateCount,
        public int $quarantinedCount,
        public int $retryScheduledCount,
        public int $permanentFailureCount,
        public ?MailboxIntakeErrorCode $errorCode,
    ) {}
}
