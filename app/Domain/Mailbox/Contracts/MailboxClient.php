<?php

namespace App\Domain\Mailbox\Contracts;

use App\Domain\Mailbox\Data\DiscoveredMailboxBatch;
use App\Domain\Mailbox\Data\MailboxCursor;
use App\Domain\Mailbox\Data\MailboxMessageReference;
use App\Domain\Mailbox\Data\MailboxProbeResult;

interface MailboxClient
{
    public function probe(): MailboxProbeResult;

    public function discover(MailboxCursor $cursor, int $limit): DiscoveredMailboxBatch;

    public function fetchRaw(MailboxMessageReference $message, int $maximumBytes): string;

    public function close(): void;
}
