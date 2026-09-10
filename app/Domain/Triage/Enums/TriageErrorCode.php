<?php

namespace App\Domain\Triage\Enums;

enum TriageErrorCode: string
{
    case ProfileInvalid = 'triage.profile_invalid';
    case NotFound = 'triage.not_found';
    case ReviewInvalid = 'triage.review_invalid';
    case CohortInvalid = 'triage.cohort_invalid';
    case OperationFailed = 'triage.operation_failed';
}
