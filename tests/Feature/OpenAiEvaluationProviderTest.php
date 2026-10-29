<?php

namespace Tests\Feature;

use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\DemoAi\DemoAiException;
use App\Domain\DemoAi\EvaluationOutput;
use App\Domain\DemoAi\InputTokenBound;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Infrastructure\DemoAi\OpenAiEvaluationProvider;
use App\Infrastructure\Email\DemoEmailNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpenAiEvaluationProviderTest extends TestCase
{
    #[Test]
    public function it_fails_closed_before_dispatch_without_a_proven_whole_request_token_bound(): void
    {
        config(['demo_ai.api_key' => 'offline-test-key']);
        Http::preventStrayRequests();
        try {
            app(OpenAiEvaluationProvider::class)->prepare($this->input(), app(ApprovedDemoInputs::class)->snapshot());
            $this->fail('Unproven token counts authorized dispatch.');
        } catch (DemoAiException $exception) {
            $this->assertSame('demo_ai.token_bound_unverified', $exception->failureCode);
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function it_constructs_the_approved_request_and_reconciles_disjoint_usage_categories(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->responseBody(), 200, ['x-request-id' => 'req-offline'])]);
        [$provider, $input, $approved, $request] = $this->prepared();

        $outcome = $provider->evaluate($request, $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);

        $this->assertSame('APPLY', $outcome->result['recommendation']);
        $this->assertSame(['ordinary_input' => 1000, 'cache_read' => 500, 'cache_write' => 500, 'output' => 400], $outcome->usage);
        $this->assertSame(368, $outcome->costMicroUsd);
        $this->assertSame('req-offline', $outcome->requestId);
        Http::assertSent(fn (Request $sent): bool => $sent['model'] === 'gpt-6-luna'
            && $sent['reasoning'] === ['effort' => 'none'] && $sent['store'] === false
            && $sent['tools'] === [] && $sent['max_output_tokens'] === 1440
            && $sent['service_tier'] === 'default' && $sent['text']['format']['strict'] === true
            && ! isset($sent['prompt_cache_retention']) && $sent['background'] === false && $sent['stream'] === false);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_charges_known_usage_for_invented_evidence_without_fabricating_a_recommendation(): void
    {
        Http::preventStrayRequests();
        $body = $this->responseBody();
        $result = $this->evaluationResult();
        $result['reasons'][0]['evidence'][0]['quote'] = 'Invented specialist credentials';
        $body['output'][0]['content'][0]['text'] = json_encode($result, JSON_THROW_ON_ERROR);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($body)]);
        [$provider, $input, $approved, $request] = $this->prepared();

        $outcome = $provider->evaluate($request, $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);

        $this->assertNull($outcome->result);
        $this->assertSame('demo_ai.evidence_invalid', $outcome->failureCode);
        $this->assertSame(368, $outcome->costMicroUsd);
        $this->assertTrue($outcome->retryable);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_retains_maximum_reservations_for_connection_loss_and_missing_cache_usage(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
        [$provider, $input, $approved, $request] = $this->prepared();
        $outcome = $provider->evaluate($request, $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);
        $this->assertTrue($outcome->uncertain);
        $this->assertFalse($outcome->retryable);
        Http::assertSentCount(1);

        $body = $this->responseBody();
        unset($body['usage']['input_tokens_details']['cache_write_tokens']);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($body)]);
        $outcome = $provider->evaluate($request, $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);
        $this->assertTrue($outcome->uncertain);
        $this->assertNull($outcome->costMicroUsd);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_records_refusals_and_truncation_as_explicit_failures_with_measured_usage(): void
    {
        foreach (['refusal', 'incomplete'] as $kind) {
            Http::preventStrayRequests();
            $body = $this->responseBody();
            if ($kind === 'refusal') {
                $body['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'Cannot evaluate.']];
            } else {
                $body['status'] = 'incomplete';
            }
            Http::fake(['https://api.openai.com/v1/responses' => Http::response($body)]);
            [$provider, $input, $approved, $request] = $this->prepared();

            $outcome = $provider->evaluate($request, $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);

            $this->assertNull($outcome->result);
            $this->assertSame(368, $outcome->costMicroUsd);
            $this->assertFalse($outcome->uncertain);
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function it_rejects_all_five_corpus_hard_incompatibilities_and_five_material_unknowns_as_apply(): void
    {
        $approved = app(ApprovedDemoInputs::class)->snapshot();
        foreach ([7, 9, 10, 11, 13, 14, 15, 16, 18, 20] as $number) {
            $input = $this->input($number);
            $result = $this->evaluationResult();
            $result['source_id'] = $input['source_id'];
            $result['reasons'][0]['evidence'] = [['field' => 'input.title', 'quote' => $input['title']]];
            try {
                app(EvaluationOutput::class)->validate($result, $input, $approved);
                $this->fail("An incompatible or unknown requirement was accepted: {$number}");
            } catch (DemoAiException $exception) {
                $this->assertSame('demo_ai.constraint_conflict', $exception->failureCode);
            }
        }
    }

    #[Test]
    public function it_does_not_infer_a_mandatory_minimum_from_an_advertised_weekly_maximum(): void
    {
        $input = $this->input();
        $input['hours_per_week_max'] = 40;
        app(EvaluationOutput::class)->validate($this->evaluationResult(), $input, app(ApprovedDemoInputs::class)->snapshot());

        $this->assertSame(40, $input['hours_per_week_max']);
    }

    #[Test]
    public function it_treats_injected_instructions_as_untrusted_input_and_rejects_an_invented_tablet_capability(): void
    {
        Http::preventStrayRequests();
        $raw = file_get_contents(base_path('tests/Fixtures/Emails/demo/adversarial/injected-instructions.eml'));
        $body = json_decode(substr($raw, strpos($raw, '{')), true, 16, JSON_THROW_ON_ERROR);
        [$provider, $input, $approved] = $this->prepared();
        $input['description'] = $body['description'];
        $input['required_devices'] = $body['required_devices'];
        $result = $this->evaluationResult();
        $result['reasons'][0]['evidence'] = [['field' => 'input.required_devices', 'quote' => 'Physical tablet']];
        $response = $this->responseBody();
        $response['output'][0]['content'][0]['text'] = json_encode($result, JSON_THROW_ON_ERROR);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($response)]);

        $outcome = $provider->evaluate($provider->prepare($input, $approved), $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);

        $this->assertNull($outcome->result);
        $this->assertSame('demo_ai.constraint_conflict', $outcome->failureCode);
        Http::assertSent(fn (Request $request): bool => str_contains($request['input'], 'Ignore the evaluation rubric')
            && ! str_contains($request['instructions'], 'Ignore the evaluation rubric') && $request['tools'] === []);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_refuses_a_bound_above_8000_before_any_network_request(): void
    {
        Http::preventStrayRequests();
        config(['demo_ai.api_key' => 'offline-test-key']);
        $tokens = $this->createStub(InputTokenBound::class);
        $tokens->method('upperBound')->willReturn(8001);
        $provider = new OpenAiEvaluationProvider(new EvaluationOutput, $tokens, app(MonotonicClock::class));
        $input = $this->input();
        $approved = app(ApprovedDemoInputs::class)->snapshot();

        $outcome = $provider->evaluate($provider->prepare($input, $approved), $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);

        $this->assertSame('demo_ai.input_token_limit', $outcome->failureCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_rejects_a_response_above_64_kib_before_json_decoding_and_retains_liability(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(str_repeat('x', 65_537))]);
        [$provider, $input, $approved, $request] = $this->prepared();

        $outcome = $provider->evaluate($request, $input, $approved, fn (array $count): bool => true, app(MonotonicClock::class)->now() + 40);

        $this->assertTrue($outcome->uncertain);
        $this->assertSame('demo_ai.response_size', $outcome->failureCode);
        $this->assertNull($outcome->costMicroUsd);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_requires_every_material_clarification_and_accepts_the_corresponding_input_field_identifiers(): void
    {
        $input = $this->input(20);
        $approved = app(ApprovedDemoInputs::class)->snapshot();
        $result = $this->evaluationResult();
        $result['source_id'] = $input['source_id'];
        $result['recommendation'] = 'MAYBE';
        $result['reasons'][0]['evidence'] = [['field' => 'input.title', 'quote' => $input['title']]];
        $result['missing_information'] = ['availability'];
        try {
            app(EvaluationOutput::class)->validate($result, $input, $approved);
            $this->fail('Material missing facts were omitted.');
        } catch (DemoAiException $exception) {
            $this->assertSame('demo_ai.missing_information_invalid', $exception->failureCode);
        }

        $result['missing_information'] = ['hours_per_week_max', 'required_devices', 'eligible_locations', 'description'];
        app(EvaluationOutput::class)->validate($result, $input, $approved);
        $this->assertCount(4, $result['missing_information']);
    }

    /** @return array{OpenAiEvaluationProvider, array<string, mixed>, array<string, mixed>, array<string, mixed>} */
    private function prepared(): array
    {
        config(['demo_ai.api_key' => 'offline-test-key']);
        $tokens = $this->createStub(InputTokenBound::class);
        $tokens->method('upperBound')->willReturn(7000);
        $provider = new OpenAiEvaluationProvider(new EvaluationOutput, $tokens, app(MonotonicClock::class));
        $input = $this->input();
        $approved = app(ApprovedDemoInputs::class)->snapshot();

        return [$provider, $input, $approved, $provider->prepare($input, $approved)];
    }

    /** @return array<string, mixed> */
    private function input(int $number = 1): array
    {
        return app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path(sprintf('tests/Fixtures/Emails/demo/%03d.eml', $number))))['input'];
    }

    /** @return array<string, mixed> */
    private function evaluationResult(): array
    {
        return [
            'source_id' => 'DEMO-001', 'recommendation' => 'APPLY',
            'reasons' => [['criterion_id' => 'scope_fit', 'explanation' => 'Bounded manual testing.',
                'evidence' => [['field' => 'input.description', 'quote' => 'Manual testing only; no automation required.']]]],
            'missing_information' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function responseBody(): array
    {
        return [
            'status' => 'completed', 'model' => 'gpt-6-luna', 'service_tier' => 'default',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($this->evaluationResult(), JSON_THROW_ON_ERROR)]]]],
            'usage' => ['input_tokens' => 2000, 'input_tokens_details' => ['cached_tokens' => 500, 'cache_write_tokens' => 500], 'output_tokens' => 400],
        ];
    }
}
