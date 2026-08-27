<?php

namespace App\Domain\Opportunities\Exceptions;

use App\Domain\Opportunities\Enums\EmailParseErrorCode;
use RuntimeException;

final class EmailParseException extends RuntimeException
{
    public function __construct(
        public readonly EmailParseErrorCode $errorCode,
        string $message = ''
    ) {
        parent::__construct($message === '' ? $errorCode->value : $message);
    }
}
