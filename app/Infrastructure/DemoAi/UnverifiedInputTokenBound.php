<?php

namespace App\Infrastructure\DemoAi;

use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\InputTokenBound;

final class UnverifiedInputTokenBound implements InputTokenBound
{
    public function assertAvailable(): void
    {
        throw new DemoAiException('demo_ai.token_bound_unverified');
    }

    public function upperBound(array $request): int
    {
        // No installed tokenizer or documented bound for provider-inserted schema/framing.
        // A characters/token estimate cannot authorize spending under a hard ceiling.
        throw new DemoAiException('demo_ai.token_bound_unverified');
    }
}
