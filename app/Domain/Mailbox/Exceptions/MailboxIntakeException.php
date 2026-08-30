<?php

namespace App\Domain\Mailbox\Exceptions;

use App\Domain\Mailbox\Enums\MailboxIntakeErrorCode;
use RuntimeException;

final class MailboxIntakeException extends RuntimeException
{
    public function __construct(
        public readonly MailboxIntakeErrorCode $errorCode,
    ) {
        parent::__construct($errorCode->value);
    }
}
