<?php

namespace App\Domain\Mailbox\Enums;

enum MailboxMessageStatus: string
{
    case Pending = 'pending';
    case RetryWait = 'retry_wait';
    case Imported = 'imported';
    case Updated = 'updated';
    case Duplicate = 'duplicate';
    case Quarantined = 'quarantined';
    case PermanentlyFailed = 'permanently_failed';
}
