<?php

namespace App\Domain\DemoAi;

use RuntimeException;

final class DemoAiException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}
