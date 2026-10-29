<?php

namespace App\Infrastructure\DemoAi;

use App\Domain\DemoAi\CountingPolicy;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\EvaluationOutput;
use App\Domain\DemoAi\EvaluationProvider;
use App\Domain\DemoAi\InputTokenBound;
use App\Domain\DemoAi\ProviderOutcome;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Triage\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;

final class OpenAiEvaluationProvider implements EvaluationProvider
{
    public function __construct(
        private readonly EvaluationOutput $output,
        private readonly InputTokenBound $tokens,
        private readonly MonotonicClock $clock,
        private readonly CountingPolicy $countingPolicy,
    ) {}

    public function prepare(array $input, array $approved): array
    {
        if (! is_string(config('demo_ai.api_key')) || config('demo_ai.api_key') === ''
            || config('demo_ai.model') !== 'gpt-6-luna'
            || config('demo_ai.pricing_version') !== 'openai-standard-2026-10-27') {
            throw new DemoAiException('demo_ai.provider_configuration');
        }
        if (strlen(CanonicalJson::encode($input)) > 12_288) {
            throw new DemoAiException('demo_ai.payload_size');
        }
        $this->tokens->assertAvailable();

        return [
            'model' => 'gpt-6-luna', 'service_tier' => 'default',
            'reasoning' => ['effort' => 'none'], 'store' => false,
            'stream' => false, 'background' => false, 'tools' => [],
            'max_output_tokens' => 1440, 'truncation' => 'disabled',
            'instructions' => CanonicalJson::encode($approved),
            'input' => CanonicalJson::encode($input),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'ai_evaluation_v1', 'strict' => true, 'schema' => $this->output->schema()]],
        ];
    }

    public function evaluate(array $request, array $input, array $approved, Closure $authorizeInference, float $deadline): ProviderOutcome
    {
        try {
            if ($request !== $this->prepare($input, $approved)) {
                throw new DemoAiException('demo_ai.request_changed');
            }
            if ($this->clock->now() + 5 >= $deadline) {
                throw new DemoAiException('demo_ai.deadline');
            }
            $requestHash = hash('sha256', CanonicalJson::encode($request));
            $countingPolicy = $this->countingPolicy->snapshot();
            $bound = $this->tokens->upperBound($request);
            if ($bound < 1 || $bound > 8000) {
                throw new DemoAiException('demo_ai.input_token_limit');
            }
            if ($request !== $this->prepare($input, $approved)
                || $this->countingPolicy->snapshot() !== $countingPolicy) {
                throw new DemoAiException('demo_ai.request_changed');
            }
            if (! $authorizeInference(['input_tokens' => $bound, 'request_sha256' => $requestHash])) {
                throw new DemoAiException('demo_ai.dispatch_cancelled');
            }
            if ($request !== $this->prepare($input, $approved)
                || $this->countingPolicy->snapshot() !== $countingPolicy) {
                throw new DemoAiException('demo_ai.request_changed');
            }
            $timeout = min(25.0, $deadline - $this->clock->now());
            if ($timeout <= 0) {
                throw new DemoAiException('demo_ai.deadline');
            }
        } catch (DemoAiException $exception) {
            return new ProviderOutcome(null, [], 0, $exception->failureCode);
        }
        try {
            $response = Http::withToken((string) config('demo_ai.api_key'))
                ->connectTimeout(min(5.0, $timeout))->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (! ctype_digit($length) || (int) $length > 65_536)) {
                            throw new DemoAiException('demo_ai.response_size');
                        }
                    },
                    'progress' => static function (float $total, float $received): void {
                        if ($received > 65_536) {
                            throw new DemoAiException('demo_ai.response_size');
                        }
                    },
                ])->post('https://api.openai.com/v1/responses', $request);
        } catch (ConnectionException|DemoAiException|GuzzleException) {
            return new ProviderOutcome(null, null, null, 'demo_ai.provider_uncertain', uncertain: true);
        }
        $headerId = $response->header('x-request-id');
        $requestId = preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', $headerId) === 1 ? $headerId : null;
        if (strlen($response->body()) > 65_536) {
            return new ProviderOutcome(null, null, null, 'demo_ai.response_size', uncertain: true, requestId: $requestId);
        }
        if (in_array($response->status(), [401, 403], true)) {
            return new ProviderOutcome(null, [], 0, 'demo_ai.provider_permission', requestId: $requestId);
        }
        // A status alone does not prove a rate-limited POST was unprocessed.
        if (! $response->successful()) {
            return new ProviderOutcome(null, null, null, 'demo_ai.provider_uncertain', uncertain: true, requestId: $requestId);
        }
        try {
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new ProviderOutcome(null, null, null, 'demo_ai.response_invalid', uncertain: true, requestId: $requestId);
        }
        if (! is_array($body)) {
            return new ProviderOutcome(null, null, null, 'demo_ai.response_invalid', uncertain: true, requestId: $requestId);
        }
        $model = is_string($body['model'] ?? null) ? $body['model'] : null;
        [$usage, $cost] = $this->usage($body['usage'] ?? null);
        if ($cost === null || ($body['service_tier'] ?? null) !== 'default' || $model !== 'gpt-6-luna') {
            return new ProviderOutcome(null, $usage, null, 'demo_ai.usage_uncertain', uncertain: true, requestId: $requestId, returnedModel: $model);
        }
        $failure = null;
        $result = null;
        try {
            if (($body['status'] ?? null) !== 'completed') {
                throw new DemoAiException('demo_ai.response_incomplete');
            }
            $messages = $body['output'] ?? [];
            if (! is_array($messages) || ! array_is_list($messages)) {
                throw new DemoAiException('demo_ai.output_invalid');
            }
            $texts = [];
            foreach ($messages as $message) {
                if (! is_array($message) || ($message['type'] ?? null) !== 'message'
                    || ! is_array($message['content'] ?? null)) {
                    throw new DemoAiException('demo_ai.output_invalid');
                }
                foreach ($message['content'] as $content) {
                    if (! is_array($content)) {
                        throw new DemoAiException('demo_ai.output_invalid');
                    }
                    if (($content['type'] ?? null) === 'refusal') {
                        throw new DemoAiException('demo_ai.refused');
                    }
                    if (($content['type'] ?? null) !== 'output_text' || ! is_string($content['text'] ?? null)) {
                        throw new DemoAiException('demo_ai.output_invalid');
                    }
                    $texts[] = $content['text'];
                }
            }
            if (count($texts) !== 1) {
                throw new DemoAiException('demo_ai.output_invalid');
            }
            $result = json_decode($texts[0], true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new DemoAiException('demo_ai.output_invalid');
            }
            $this->output->validate($result, $input, $approved);
        } catch (JsonException) {
            $failure = 'demo_ai.output_invalid';
            $result = null;
        } catch (DemoAiException $exception) {
            $failure = $exception->failureCode;
            $result = null;
        }

        $retryAfter = $response->header('Retry-After');
        $retrySeconds = ctype_digit($retryAfter) ? min(86400, (int) $retryAfter) : 30;
        if ($retryAfter !== '' && ! ctype_digit($retryAfter)) {
            $timestamp = strtotime($retryAfter);
            if ($timestamp !== false) {
                $retrySeconds = max(30, min(86400, $timestamp - CarbonImmutable::now('UTC')->getTimestamp()));
            }
        }

        return new ProviderOutcome($result, $usage, $cost, $failure, retryable: $failure !== null && $failure !== 'demo_ai.refused', retryAfterSeconds: max(30, $retrySeconds), requestId: $requestId, returnedModel: $model);
    }

    /** @return array{array<string, int>|null, int|null} */
    private function usage(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [null, null];
        }
        $input = $usage['input_tokens'] ?? null;
        $reads = $usage['input_tokens_details']['cached_tokens'] ?? null;
        $writes = $usage['input_tokens_details']['cache_write_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        foreach ([$input, $reads, $writes, $output] as $count) {
            if (! is_int($count) || $count < 0) {
                return [null, null];
            }
        }
        if ($reads + $writes > $input || $input > 8000 || $output > 1440) {
            return [null, null];
        }
        $ordinary = $input - $reads - $writes;
        $cost = intdiv($ordinary * 100 + $reads * 10 + $writes * 125 + $output * 500 + 999, 1000);

        return [['ordinary_input' => $ordinary, 'cache_read' => $reads, 'cache_write' => $writes, 'output' => $output], $cost];
    }
}
