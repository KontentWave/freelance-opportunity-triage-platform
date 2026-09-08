<?php

namespace App\Infrastructure\Time;

use App\Domain\Mailbox\Contracts\MonotonicClock;

final class SystemMonotonicClock implements MonotonicClock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
