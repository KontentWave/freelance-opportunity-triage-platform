<?php

namespace Tests\Support\Fakes;

use App\Domain\Mailbox\Contracts\MonotonicClock;

final class FakeMonotonicClock implements MonotonicClock
{
    public function __construct(private float $time = 0.0) {}

    public function now(): float
    {
        return $this->time;
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
