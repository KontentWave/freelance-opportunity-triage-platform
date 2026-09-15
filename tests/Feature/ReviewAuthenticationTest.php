<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReviewAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_protects_private_pages_and_expires_logged_out_sessions(): void
    {
        config()->set('opportunity_review.profile_path', resource_path('triage/profiles/demo-v1.json'));
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->get('/opportunities')->assertRedirect('/login');
        $this->getJson('/review/v1/opportunities')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data');

        $this->post('/login', [
            'email' => 'OWNER@example.test',
            'password' => 'correct-password',
        ])->assertRedirect('/opportunities');

        $this->assertAuthenticatedAs($user);
        $this->getJson('/review/v1/opportunities')->assertOk();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->getJson('/review/v1/opportunities')->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_unassigned_accounts_with_a_safe_setup_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/review/v1/opportunities')
            ->assertForbidden()
            ->assertExactJson([
                'error_code' => 'review.workspace_unavailable',
                'message' => 'This account is not assigned to a review workspace.',
            ]);
    }

    #[Test]
    public function it_throttles_repeated_failed_login_attempts(): void
    {
        User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->from('/login')->post('/login', [
                'email' => 'owner@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->from('/login')->post('/login', [
            'email' => 'owner@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    #[Test]
    public function it_returns_a_safe_error_when_the_server_profile_is_unavailable(): void
    {
        config()->set('opportunity_review.profile_path', null);
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user)
            ->getJson('/review/v1/opportunities')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error_code' => 'triage.profile_invalid',
                'message' => 'The review profile is currently unavailable.',
            ]);
    }
}
