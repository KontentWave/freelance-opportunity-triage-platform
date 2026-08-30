<?php

namespace App\Domain\Mailbox\Data;

use Carbon\CarbonImmutable;

final readonly class MailboxCursor
{
    public function __construct(
        public ?int $uidValidity,
        public int $lastDiscoveredUid,
        public ?CarbonImmutable $initialLookbackAt,
    ) {}
}
