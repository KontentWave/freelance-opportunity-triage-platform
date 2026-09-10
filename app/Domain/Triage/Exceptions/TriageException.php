<?php

namespace App\Domain\Triage\Exceptions;

use App\Domain\Triage\Enums\TriageErrorCode;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class TriageException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly TriageErrorCode $errorCode,
    ) {
        parent::__construct($errorCode->value);
    }
}
