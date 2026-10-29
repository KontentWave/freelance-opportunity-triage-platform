<?php

namespace App\Domain\DemoAi;

interface InputTokenBound
{
    public function assertAvailable(): void;

    /** Includes instructions, profile, schema and provider framing, not just the email.
     * @param  array<string, mixed>  $request
     */
    public function upperBound(array $request): int;
}
