<?php

namespace App\Domain\Mailbox\Data;

final readonly class MailboxProbeResult
{
    public function __construct(
        public bool $successful,
    ) {}
}
