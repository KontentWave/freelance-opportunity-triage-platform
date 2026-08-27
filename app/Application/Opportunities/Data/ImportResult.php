<?php

namespace App\Application\Opportunities\Data;

use App\Domain\Opportunities\Enums\EmailImportStatus;
use App\Domain\Opportunities\Enums\EmailParseErrorCode;

final readonly class ImportResult
{
    public function __construct(
        public EmailImportStatus $status,
        public ?string $opportunityId,
        public ?string $externalJobId,
        public ?EmailParseErrorCode $errorCode,
    ) {}
}
