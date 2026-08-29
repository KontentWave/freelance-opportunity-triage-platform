<?php

namespace App\Domain\Opportunities\Contracts;

use App\Domain\Opportunities\Data\ParsedOpportunity;
use App\Domain\Opportunities\Exceptions\EmailParseException;

interface OpportunityEmailParser
{
    /** @throws EmailParseException */
    public function parse(string $rawEmail): ParsedOpportunity;
}
