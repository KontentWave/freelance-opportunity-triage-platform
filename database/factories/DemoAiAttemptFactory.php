<?php

namespace Database\Factories;

use App\Models\DemoAiAttempt;
use App\Models\DemoAiItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoAiAttempt>
 */
class DemoAiAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => DemoAiItem::factory(), 'attempt_number' => 1,
            'dispatch_day' => now('UTC')->toDateString(), 'status' => 'in_flight',
            'fence' => 1, 'reserved_micro_usd' => 1892, 'pricing_snapshot' => ['version' => 'openai-standard-2026-10-27'],
            'request_provenance' => ['requested_model' => 'gpt-6-luna'], 'dispatched_at' => now('UTC'),
        ];
    }
}
