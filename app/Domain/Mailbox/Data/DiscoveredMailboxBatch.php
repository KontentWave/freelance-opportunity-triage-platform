<?php

namespace App\Domain\Mailbox\Data;

final readonly class DiscoveredMailboxBatch
{
    /** @param list<MailboxMessageReference> $messages */
    public function __construct(
        public int $uidValidity,
        public array $messages,
        public int $highestDiscoveredUid,
    ) {}
}
