<?php

namespace Database\Factories;

use App\Models\Opportunity;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
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
            'provider' => 'upwork_email',
            'external_id' => (string) fake()->unique()->numerify('2##################'),
            'canonical_url' => 'https://www.upwork.com/jobs/~'.fake()->unique()->numerify('2##################'),
            'title' => fake()->sentence(4),
            'contract_type' => 'hourly',
            'hourly_min' => '40.00',
            'hourly_max' => '60.00',
            'currency' => 'USD',
            'estimated_duration' => 'More than 6 months',
            'posted_on' => now()->toDateString(),
            'excerpt' => fake()->sentence(),
            'hidden_skill_count' => 0,
            'payment_verified' => true,
            'client_rating' => '4.75',
            'client_spend_usd' => '79000.00',
            'client_spend_approximate' => true,
            'client_country' => 'United States',
            'source_template' => 'upwork-alert-hourly-v1',
        ];
    }
}
