<?php

namespace App\Domain\Mailbox\Contracts;

interface MonotonicClock
{
    public function now(): float;
}
