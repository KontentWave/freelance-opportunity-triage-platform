<?php

namespace App\Domain\Mailbox\Enums;

enum MailboxRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case SkippedOverlap = 'skipped_overlap';
}
