<?php

namespace App\Domain\Triage\Enums;

enum TriageRecommendation: string
{
    case Apply = 'APPLY';
    case Maybe = 'MAYBE';
    case Skip = 'SKIP';
}
