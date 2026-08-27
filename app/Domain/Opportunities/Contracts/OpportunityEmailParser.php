<?php

namespace App\Domain\Opportunities\Contracts;

use App\Domain\Opportunities\Data\ParsedOpportunity;

interface OpportunityEmailParser
{
    /** @throws \App\Domain\Opportunities\Exceptions\EmailParseException */
    public function parse(string $rawEmail): ParsedOpportunity;
}
