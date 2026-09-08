<?php

namespace App\Domain\Mailbox\Data;

use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use App\Domain\Mailbox\Exceptions\MailboxIntakeException;

class MailboxPollBudget
{
    public const WORK_SECONDS = 480;

    public const FINALIZATION_SECONDS = 60;

    public const CLEANUP_SECONDS = 30;

    public const LOCK_RELEASE_SECONDS = 30;

    private function __construct(
        private readonly MonotonicClock $clock,
        private readonly float $startedAt,
    ) {}

    public static function start(MonotonicClock $clock): self
    {
        return new self($clock, $clock->now());
    }

    /** @throws MailboxIntakeException */
    public function throwIfWorkExhausted(): void
    {
        if ($this->remainingWorkSeconds() <= 0.0) {
            throw new MailboxIntakeException(MailboxIntakeErrorCode::PollBudgetExhausted);
        }
    }

    public function remainingWorkSeconds(): float
    {
        return max(0.0, $this->workDeadline() - $this->clock->now());
    }

    public function remainingCleanupSeconds(): float
    {
        return max(0.0, $this->cleanupDeadline() - $this->clock->now());
    }

    public function remainingFinalizationSeconds(): float
    {
        return max(0.0, $this->finalizationDeadline() - $this->clock->now());
    }

    public function remainingLockSeconds(): float
    {
        return max(0.0, $this->lockDeadline() - $this->clock->now());
    }

    public function clock(): MonotonicClock
    {
        return $this->clock;
    }

    public function workDeadline(): float
    {
        return $this->startedAt + self::WORK_SECONDS;
    }

    public function finalizationDeadline(): float
    {
        return $this->workDeadline() + self::FINALIZATION_SECONDS;
    }

    public function cleanupDeadline(): float
    {
        return $this->finalizationDeadline() + self::CLEANUP_SECONDS;
    }

    public function lockDeadline(): float
    {
        return $this->cleanupDeadline() + self::LOCK_RELEASE_SECONDS;
    }
}
