<?php

namespace Tests\Feature;

use App\Jobs\ProcessDemoAiRun;
use App\Models\DemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DemoAiRunEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_admits_only_server_owned_configuration_and_returns_session_owned_status(): void
    {
        $this->configureUser();
        Queue::fake([ProcessDemoAiRun::class]);
        $key = $this->getJson('/demo-ai/admission-key')->assertOk()->json('idempotency_key');
        $id = $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertAccepted()->json('id');
        $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertAccepted()->assertJsonPath('id', $id);
        $this->getJson('/demo-ai/runs/'.$id)->assertOk()->assertJsonPath('status', 'queued');
        $this->getJson('/demo-ai/runs/'.$id)->assertTooManyRequests()->assertHeader('Retry-After', '2');

        $this->assertDatabaseCount('demo_ai_runs', 1);
        $this->assertDatabaseCount('opportunities', 0);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_rejects_source_profile_provider_and_workspace_overrides_without_reserving(): void
    {
        $this->configureUser();
        Queue::fake([ProcessDemoAiRun::class]);
        $key = $this->getJson('/demo-ai/admission-key')->assertOk()->json('idempotency_key');
        foreach (['source', 'profile', 'provider', 'workspace_id', 'recipient', 'input'] as $field) {
            $this->postJson('/demo-ai/runs', ['idempotency_key' => $key, $field => 'forged'])->assertUnprocessable();
        }

        $this->assertDatabaseCount('demo_ai_runs', 0);
        $this->assertDatabaseCount('demo_ai_budgets', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_returns_the_same_not_found_for_foreign_sessions_foreign_workspaces_and_missing_runs(): void
    {
        $user = $this->configureUser();
        $foreignSession = DemoAiRun::factory()->create(['workspace_id' => $user->workspace_id]);
        $foreignWorkspace = DemoAiRun::factory()->create();

        $this->getJson('/demo-ai/runs/'.$foreignSession->id)->assertNotFound();
        $this->getJson('/demo-ai/runs/'.$foreignWorkspace->id)->assertNotFound();
        $this->getJson('/demo-ai/runs/01AAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
    }

    #[Test]
    public function it_rejects_disabled_or_private_mode_and_unsigned_admission_keys(): void
    {
        $this->configureUser();
        config(['demo_ai.enabled' => false]);
        $this->postJson('/demo-ai/runs', ['idempotency_key' => str_repeat('a', 48)])->assertNotFound();
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'private']);
        $this->getJson('/demo-ai/admission-key')->assertNotFound();
        config(['opportunity_review.mode' => 'demo']);
        $this->postJson('/demo-ai/runs', ['idempotency_key' => str_repeat('a', 48)])->assertUnprocessable();

        $this->assertDatabaseCount('demo_ai_runs', 0);
    }

    #[Test]
    public function it_rejects_an_expired_first_submission_and_renews_only_on_explicit_preparation(): void
    {
        $this->configureUser();
        Queue::fake([ProcessDemoAiRun::class]);
        $key = $this->getJson('/demo-ai/admission-key')->assertOk()->json('idempotency_key');
        $until = time();
        $this->withSession(['demo_ai_keys' => [$key => $until]]);

        $this->getJson('/demo-ai/admission-key')->assertOk()->assertJsonPath('idempotency_key', $key)
            ->assertJsonPath('key_expires_at', $until)->assertJsonPath('run', null);
        $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key')
            ->assertJsonPath('errors.idempotency_key.0', 'Request a current server-issued admission key.');
        $fresh = $this->postJson('/demo-ai/admission-key')->assertOk()->assertJsonPath('run', null)
            ->json('idempotency_key');

        $this->assertNotSame($key, $fresh);
        $this->assertDatabaseCount('demo_ai_runs', 0);
        $this->assertDatabaseCount('demo_ai_budgets', 0);
        Queue::assertNothingPushed();

        $this->postJson('/demo-ai/runs', ['idempotency_key' => $fresh])->assertAccepted();
        $this->assertDatabaseCount('demo_ai_runs', 1);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_enforces_csrf_on_the_real_web_trigger(): void
    {
        $this->configureUser();
        Queue::fake([ProcessDemoAiRun::class]);
        $this->app->bind(PreventRequestForgery::class, fn ($app): PreventRequestForgery => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $key = $this->getJson('/demo-ai/admission-key')->assertOk()->json('idempotency_key');

        $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertStatus(419);

        $this->assertDatabaseCount('demo_ai_runs', 0);
        Queue::assertNothingPushed();
    }

    private function configureUser(): User
    {
        $user = User::factory()->create(['workspace_id' => Workspace::factory()]);
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo', 'opportunity_review.demo_user_id' => $user->id]);
        $this->actingAs($user);

        return $user;
    }
}
