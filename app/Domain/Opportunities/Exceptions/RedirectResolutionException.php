<?php

namespace App\Domain\Opportunities\Exceptions;

use App\Domain\Opportunities\Enums\RedirectResolutionErrorCode;
use RuntimeException;

final class RedirectResolutionException extends RuntimeException
{
    public function __construct(public readonly RedirectResolutionErrorCode $errorCode)
    {
        parent::__construct($errorCode->value);
    }
}
