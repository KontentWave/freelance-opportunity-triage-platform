<?php

namespace App\Domain\Opportunities\Contracts;

use App\Domain\Opportunities\Data\RedirectHopRequest;
use App\Domain\Opportunities\Data\RedirectHopResponse;

interface RedirectHopClient
{
    public function head(RedirectHopRequest $request): RedirectHopResponse;
}
