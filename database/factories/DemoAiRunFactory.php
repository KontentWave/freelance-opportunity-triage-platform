<?php

namespace Database\Factories;

use App\Domain\DemoAi\ApprovedDemoInputs;
use App\Domain\Triage\Support\CanonicalJson;
use App\Models\DemoAiRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoAiRun>
 */
class DemoAiRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'session_hash' => hash('sha256', fake()->uuid()),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'idempotency_hash' => hash('sha256', fake()->uuid()),
            'status' => 'queued',
            'reservation_day' => now('UTC')->toDateString(),
            'reserved_micro_usd' => 0,
            'approved_snapshot' => app(ApprovedDemoInputs::class)->snapshot(),
            'approved_snapshot_sha256' => fn (array $attributes): string => hash('sha256', CanonicalJson::encode($attributes['approved_snapshot'])),
            'version_hash' => app(ApprovedDemoInputs::class)->versionHash(),
            'admitted_at' => now('UTC'),
            'deadline_at' => now('UTC')->addMinutes(30),
        ];
    }
}
