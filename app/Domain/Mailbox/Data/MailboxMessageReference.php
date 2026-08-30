<?php

namespace App\Domain\Mailbox\Data;

final readonly class MailboxMessageReference
{
    public function __construct(
        public int $uid,
        public int $reportedSize,
    ) {}
}
