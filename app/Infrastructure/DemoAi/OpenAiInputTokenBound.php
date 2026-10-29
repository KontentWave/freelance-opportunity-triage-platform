<?php

namespace App\Infrastructure\DemoAi;

use App\Domain\DemoAi\CountingPolicy;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\InputTokenBound;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;

final class OpenAiInputTokenBound implements InputTokenBound
{
    public function __construct(private readonly CountingPolicy $policy) {}

    public function assertAvailable(): void
    {
        $this->policy->assertAvailable();
        if (! is_string(config('demo_ai.api_key')) || config('demo_ai.api_key') === ''
            || config('demo_ai.model') !== 'gpt-6-luna'
            || config('demo_ai.pricing_version') !== 'openai-standard-2026-10-27') {
            throw new DemoAiException('demo_ai.provider_configuration');
        }
    }

    public function upperBound(array $request): int
    {
        $this->assertAvailable();
        $fixed = [
            'model' => 'gpt-6-luna', 'service_tier' => 'default',
            'reasoning' => ['effort' => 'none'], 'store' => false,
            'stream' => false, 'background' => false, 'tools' => [],
            'max_output_tokens' => 1440, 'truncation' => 'disabled',
        ];
        if (array_diff(array_keys($request), [...array_keys($fixed), 'instructions', 'input', 'text']) !== []
            || array_intersect_key($request, $fixed) !== $fixed
            || ! is_string($request['instructions'] ?? null) || ! is_string($request['input'] ?? null)
            || ! mb_check_encoding($request['instructions'], 'UTF-8') || ! mb_check_encoding($request['input'], 'UTF-8')
            || ! is_array($request['text']['format']['schema'] ?? null)
            || ($request['text']['format']['type'] ?? null) !== 'json_schema'
            || ($request['text']['format']['strict'] ?? null) !== true) {
            throw new DemoAiException('demo_ai.count_configuration');
        }
        // Generation/retention flags are not count parameters; every input-affecting field is retained.
        $payload = array_intersect_key($request, array_flip(['model', 'instructions', 'input', 'reasoning', 'text', 'tools', 'truncation']));
        try {
            $response = Http::withToken(config('demo_ai.api_key'))
                ->connectTimeout(3)->timeout(5)->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (! ctype_digit($length) || (int) $length > 4096)) {
                            throw new DemoAiException('demo_ai.count_failed');
                        }
                    },
                    'progress' => static function (float $total, float $received): void {
                        if ($received > 4096) {
                            throw new DemoAiException('demo_ai.count_failed');
                        }
                    },
                ])->post('https://api.openai.com/v1/responses/input_tokens', $payload);
        } catch (ConnectionException|GuzzleException|DemoAiException) {
            throw new DemoAiException('demo_ai.count_failed');
        }
        if ($response->status() !== 200 || strlen($response->body()) > 4096) {
            throw new DemoAiException('demo_ai.count_failed');
        }
        try {
            $body = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DemoAiException('demo_ai.count_invalid');
        }
        if (! is_array($body) || ($body['object'] ?? null) !== 'response.input_tokens'
            || ! is_int($body['input_tokens'] ?? null) || $body['input_tokens'] < 1) {
            throw new DemoAiException('demo_ai.count_invalid');
        }

        return $body['input_tokens'];
    }
}
