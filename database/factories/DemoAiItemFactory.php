<?php

namespace Database\Factories;

use App\Infrastructure\Email\DemoEmailNormalizer;
use App\Models\DemoAiItem;
use App\Models\DemoAiRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemoAiItem>
 */
class DemoAiItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = app(DemoEmailNormalizer::class)->parse(file_get_contents(base_path('tests/Fixtures/Emails/demo/001.eml')));

        return [
            'run_id' => DemoAiRun::factory(),
            'workspace_id' => fn (array $attributes): string => DemoAiRun::query()->findOrFail($attributes['run_id'])->workspace_id,
            'ordinal' => 0, 'source_namespace' => $source['source_namespace'], 'source_id' => $source['source_id'],
            'mailbox_key' => 'authored-demo', 'uidvalidity' => 1, 'uid' => 1,
            'body_sha256' => $source['body_sha256'], 'input_sha256' => $source['input_sha256'],
            'raw_sha256' => $source['raw_sha256'], 'input_snapshot' => $source['input'], 'status' => 'pending',
        ];
    }
}
