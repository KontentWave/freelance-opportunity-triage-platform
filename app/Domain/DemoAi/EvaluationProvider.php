<?php

namespace App\Domain\DemoAi;

use Closure;

interface EvaluationProvider
{
    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $approved
     * @return array<string, mixed>
     */
    public function prepare(array $input, array $approved): array;

    /** @param array<string, mixed> $request
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $approved
     * @param  Closure(array{input_tokens: int, request_sha256: string}): bool  $authorizeInference
     */
    public function evaluate(array $request, array $input, array $approved, Closure $authorizeInference, float $deadline): ProviderOutcome;
}
