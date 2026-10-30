<?php

namespace Tests\Feature;

use App\Application\DemoAi\DemoAiLedger;
use App\Domain\DemoAi\ProviderOutcome;
use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Jobs\ProcessDemoAiRun;
use App\Models\DemoAiAttempt;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DemoAiReportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_restores_the_server_issued_intent_and_run_without_a_second_admission(): void
    {
        $this->demo();
        $key = $this->getJson('/demo-ai/admission-key')->json('idempotency_key');
        $this->getJson('/demo-ai/admission-key')->assertJsonPath('idempotency_key', $key);
        $id = $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->json('id');

        $this->getJson('/demo-ai/admission-key')->assertJsonPath('run.id', $id)->assertJsonPath('idempotency_key', $key);
        $this->postJson('/demo-ai/admission-key')->assertConflict();
        $this->travel(31)->minutes();
        $this->withSession(['demo_ai_keys' => [$key => time()]]);
        $this->getJson('/demo-ai/admission-key')->assertJsonPath('run.id', $id)->assertJsonPath('idempotency_key', $key);
        $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertAccepted()->assertJsonPath('id', $id);
        app(DemoAiLedger::class)->fail($id, 'demo_ai.deadline');
        $new = $this->postJson('/demo-ai/admission-key')->assertOk()->json('idempotency_key');

        $this->assertNotSame($key, $new);
        $this->assertDatabaseCount('demo_ai_runs', 1);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_replays_an_old_tab_intent_without_admitting_again_after_the_current_version_changes(): void
    {
        [$ledger, $run] = $this->admitted();
        $key = $this->app['session']->get('demo_ai_intent');
        $version = $run->version_hash;
        $historical = hash('sha256', 'historical approved version');
        DB::table('demo_ai_runs')->where('id', $run->id)->update([
            'version_hash' => $historical,
            'idempotency_hash' => hash('sha256', implode('|', [$run->workspace_id, $run->session_hash, $key, $historical])),
        ]);
        $this->withSession(['demo_ai_key_versions' => [$key => $historical]]);

        $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertAccepted()->assertJsonPath('id', $run->id);

        $this->assertNotSame($version, $historical);
        $this->assertDatabaseCount('demo_ai_runs', 1);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_requires_a_new_intent_when_approved_inputs_change_before_the_first_submission(): void
    {
        $this->demo();
        $key = $this->getJson('/demo-ai/admission-key')->json('idempotency_key');
        $this->withSession(['demo_ai_key_versions' => [$key => hash('sha256', 'superseded version')]]);

        $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('demo_ai_runs', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_reports_numeric_zero_counts_for_admission_and_a_rejected_run_without_evaluation(): void
    {
        $this->demo();
        $key = $this->getJson('/demo-ai/admission-key')->json('idempotency_key');
        $id = $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertAccepted()
            ->assertJsonPath('selected_count', 0)->assertJsonPath('succeeded_count', 0)
            ->assertJsonPath('failed_count', 0)->json('id');
        app(DemoAiLedger::class)->fail($id, 'demo_ai.disabled');
        $new = $this->postJson('/demo-ai/admission-key')->json('idempotency_key');

        $rejected = $this->postJson('/demo-ai/runs', ['idempotency_key' => $new])->assertTooManyRequests()
            ->assertJsonPath('status', 'rejected')->assertJsonPath('selected_count', 0)
            ->assertJsonPath('succeeded_count', 0)->assertJsonPath('failed_count', 0)->json('id');
        $this->get('/demo-ai/runs/'.$rejected.'/report')->assertOk()
            ->assertSee('0 selected; 0 evaluated successfully; 0 unsuccessful or unconfirmed.')
            ->assertSee('No evaluation was started.');

        $this->assertDatabaseCount('demo_ai_attempts', 0);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_serves_identical_escaped_self_contained_html_from_the_stored_snapshot_without_external_work(): void
    {
        [$ledger, $run] = $this->admitted();
        $started = $ledger->start($run->id);
        $source = DemoAiItem::factory()->make()->toArray();
        $hostile = '<img src="https://tracking.invalid/pixel" onerror="alert(1)"><script>steal()</script>';
        $source['input_snapshot']['title'] = $hostile;
        $item = DemoAiItem::factory()->create([
            'run_id' => $run->id, 'workspace_id' => $run->workspace_id,
            'input_snapshot' => $source['input_snapshot'], 'status' => 'succeeded',
            'result' => ['recommendation' => 'MAYBE', 'reasons' => [[
                'criterion_id' => 'uncertainty', 'explanation' => $hostile,
                'evidence' => [['field' => 'input.title', 'quote' => $hostile]],
            ]], 'missing_information' => ['availability']],
        ]);
        DB::table('demo_ai_runs')->where('id', $run->id)->update(['selected_count' => 1]);
        $ledger->fail($run->id, 'demo_ai.disabled');
        $snapshot = $run->fresh()->report_snapshot;
        Http::preventStrayRequests();
        Mail::fake();
        $this->assertNotNull($started);

        $screen = $this->get('/demo-ai/runs/'.$run->id.'/report')->assertOk();
        $download = $this->get('/demo-ai/runs/'.$run->id.'/report.html')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="fictional-demo-'.$run->id.'.html"')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertSame($screen->getContent(), $download->getContent());
        $screen->assertSee($hostile)->assertDontSee($hostile, false)
            ->assertSee('iPhone 11')->assertSee('No fixed minimum rate')
            ->assertSee('conditional application')->assertSee('manageable AI-assisted development')
            ->assertDontSee('UNEVALUATED');
        $this->assertStringNotContainsString('<script', $screen->getContent());
        $this->assertDoesNotMatchRegularExpression('/<(?:img|script|link|iframe)\\b/i', $screen->getContent());
        $this->assertStringContainsString('@media print', $screen->getContent());
        $this->assertStringContainsString("default-src 'none'", $screen->headers->get('Content-Security-Policy'));
        $this->assertSame($snapshot, $run->fresh()->report_snapshot);
        $this->assertSame($item->result, $run->fresh()->report_snapshot['items'][0]['result']);
        $this->assertDatabaseCount('demo_ai_attempts', 0);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_uses_historical_versions_profile_prompt_input_and_provider_provenance_not_current_configuration(): void
    {
        [$ledger, $run] = $this->admitted();
        $approved = $run->approved_snapshot;
        $approved['profile_version'] = 'historical-public-profile';
        $approved['prompt_version'] = 'historical-public-prompt';
        $approved['profile']['devices'] = 'Historical approved device evidence';
        $approved['prompt'] = ['Historical public prompt text'];
        DB::table('demo_ai_runs')->where('id', $run->id)->update(['approved_snapshot' => json_encode($approved)]);
        $item = DemoAiItem::factory()->create(['run_id' => $run->id, 'workspace_id' => $run->workspace_id, 'status' => 'uncertain']);
        DemoAiAttempt::factory()->create([
            'item_id' => $item->id, 'status' => 'uncertain',
            'request_provenance' => ['provider' => 'Historical provider', 'requested_model' => 'historical-requested-model'],
            'returned_model' => 'historical-returned-model', 'provider_request_id' => 'historical-request-id',
        ]);
        $ledger->fail($run->id, 'demo_ai.accounting_blocked');
        config(['demo_ai.model' => 'wrong-current-model']);

        $this->get('/demo-ai/runs/'.$run->id.'/report')->assertOk()
            ->assertSee('historical-public-profile')->assertSee('historical-public-prompt')
            ->assertSee('Historical approved device evidence')->assertSee('Historical public prompt text')
            ->assertSee('historical-requested-model')->assertSee('historical-returned-model')
            ->assertSee('Historical provider')
            ->assertSee('historical-request-id')->assertSee('UNEVALUATED')
            ->assertDontSee('wrong-current-model');
        $this->getJson('/demo-ai/runs/'.$run->id)->assertJsonPath('profile_version', 'historical-public-profile');
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_denies_foreign_sessions_workspaces_private_mode_and_guests_without_record_disclosure(): void
    {
        $user = $this->demo();
        $foreignSession = DemoAiRun::factory()->create(['workspace_id' => $user->workspace_id]);
        $foreignWorkspace = DemoAiRun::factory()->create();
        foreach ([$foreignSession->id, $foreignWorkspace->id, '01AAAAAAAAAAAAAAAAAAAAAAAA'] as $id) {
            foreach (['/report', '/report.html', ''] as $suffix) {
                $this->getJson('/demo-ai/runs/'.$id.$suffix)->assertNotFound();
            }
        }
        config(['opportunity_review.mode' => 'private']);
        $this->get('/demo-ai')->assertNotFound();
        $this->getJson('/demo-ai/runs/'.$foreignSession->id.'/report')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/demo-ai/runs/'.$foreignSession->id.'/report.html')->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_expires_all_report_surfaces_at_exactly_seven_days_and_purges_only_feature_content(): void
    {
        [$ledger, $run] = $this->admitted();
        $item = DemoAiItem::factory()->create(['run_id' => $run->id, 'workspace_id' => $run->workspace_id]);
        $attempt = DemoAiAttempt::factory()->create(['item_id' => $item->id, 'status' => 'uncertain', 'reserved_micro_usd' => 1892]);
        $ledger->fail($run->id, 'demo_ai.accounting_blocked');
        $other = DemoAiRun::factory()->create();
        $this->travelTo($run->fresh()->expires_at->subSecond());
        $this->get('/demo-ai/runs/'.$run->id.'/report')->assertOk();
        $this->travel(1)->seconds();

        foreach (['', '/report', '/report.html'] as $suffix) {
            $this->getJson('/demo-ai/runs/'.$run->id.$suffix)->assertGone();
        }
        $this->get('/demo-ai/runs/'.$run->id.'/report')->assertSee('expired after seven days')
            ->assertSee('does not restart evaluation');
        $ledger->recover();

        $this->assertNull($run->fresh()->report_snapshot);
        $this->assertNull($run->fresh()->approved_snapshot);
        $this->assertNull($item->fresh()->input_snapshot);
        $this->assertNotNull($other->fresh()->approved_snapshot);
        $this->assertSame(1892, $attempt->fresh()->reserved_micro_usd);
        $this->assertSame('uncertain', $attempt->fresh()->status);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_does_not_rewrite_a_finalized_report_after_late_settlement_or_recovery(): void
    {
        [$ledger, $run] = $this->admitted();
        $started = $ledger->start($run->id);
        $input = app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml')));
        $ledger->select($run->id, ['sources' => [$input + ['uid' => 1, 'uidvalidity' => 1, 'mailbox_key' => 'authored-demo']]], $started->discovery_token);
        $item = DemoAiItem::query()->sole();
        $attempt = $ledger->claim($item->id, ['model' => 'gpt-6-luna']);
        config(['demo_ai.enabled' => false]);
        $ledger->recover();
        $before = $this->get('/demo-ai/runs/'.$run->id.'/report')->getContent();
        $snapshot = $run->fresh()->report_snapshot;

        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));
        $ledger->recover();

        $this->assertSame($snapshot, $run->fresh()->report_snapshot);
        $this->assertSame($before, $this->get('/demo-ai/runs/'.$run->id.'/report.html')->getContent());
        $this->assertDatabaseHas('demo_ai_attempts', ['id' => $attempt->id, 'status' => 'known', 'settled_micro_usd' => 10]);
        Queue::assertPushed(ProcessDemoAiRun::class, 2);
    }

    #[Test]
    public function it_keeps_seeded_mode_available_and_does_not_generate_a_report_for_unfinished_work(): void
    {
        [$ledger, $run] = $this->admitted();
        config(['demo_ai.enabled' => false]);

        $this->get('/demo-ai')->assertOk();
        $this->getJson('/demo-ai/admission-key')->assertJsonPath('available', false)->assertJsonPath('run.id', $run->id);
        $this->get('/demo-ai/runs/'.$run->id.'/report')->assertConflict();
        $this->assertNull($run->fresh()->report_snapshot);
        Queue::assertPushed(ProcessDemoAiRun::class, 1);
    }

    #[Test]
    public function it_does_not_reconstruct_a_pre_snapshot_historical_report_during_late_settlement(): void
    {
        [$ledger, $run] = $this->admitted();
        $started = $ledger->start($run->id);
        $source = app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml')));
        $ledger->select($run->id, ['sources' => [$source + ['uid' => 1, 'uidvalidity' => 1, 'mailbox_key' => 'authored-demo']]], $started->discovery_token);
        $attempt = $ledger->claim(DemoAiItem::query()->sole()->id, ['model' => 'gpt-6-luna']);
        DB::table('demo_ai_runs')->where('id', $run->id)->update([
            'status' => 'failed', 'failure_code' => 'demo_ai.disabled',
            'finalized_at' => now(), 'expires_at' => now()->addDays(7),
        ]);

        $ledger->settle($attempt->id, new ProviderOutcome(['recommendation' => 'APPLY'], [], 10, null));

        $this->assertNull($run->fresh()->report_snapshot);
        $this->get('/demo-ai/runs/'.$run->id.'/report')->assertConflict();
        $this->assertDatabaseHas('demo_ai_attempts', ['id' => $attempt->id, 'status' => 'known', 'settled_micro_usd' => 10]);
        Queue::assertPushed(ProcessDemoAiRun::class, 2);
    }

    private function demo(): User
    {
        $this->freezeTime();
        Queue::fake([ProcessDemoAiRun::class]);
        $user = User::factory()->create(['workspace_id' => Workspace::factory()]);
        config(['demo_ai.enabled' => true, 'opportunity_review.mode' => 'demo', 'opportunity_review.demo_user_id' => $user->id]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array{DemoAiLedger, DemoAiRun} */
    private function admitted(): array
    {
        $this->demo();
        $key = $this->getJson('/demo-ai/admission-key')->json('idempotency_key');
        $id = $this->postJson('/demo-ai/runs', ['idempotency_key' => $key])->assertAccepted()->json('id');

        return [app(DemoAiLedger::class), DemoAiRun::query()->findOrFail($id)];
    }
}
