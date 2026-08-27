<?php

namespace App\Domain\Opportunities\Enums;

enum EmailImportStatus: string
{
    case Imported = 'imported';
    case Updated = 'updated';
    case Duplicate = 'duplicate';
    case Quarantined = 'quarantined';
}
