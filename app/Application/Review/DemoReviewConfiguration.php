<?php

namespace App\Application\Review;

use App\Models\User;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DemoReviewConfiguration
{
    public function enabled(): bool
    {
        return config('opportunity_review.mode') === 'demo';
    }

    public function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            abort(404);
        }
    }

    public function user(): User
    {
        $configuredId = filter_var(config('opportunity_review.demo_user_id'), FILTER_VALIDATE_INT);
        $user = $configuredId === false
            ? null
            : User::query()->whereKey($configuredId)->whereNotNull('workspace_id')->first();

        if ($user === null) {
            throw new HttpException(503, 'The synthetic demonstration is unavailable.');
        }

        return $user;
    }

    /** @return array{label: string, full_description: string, overrides: array<string, mixed>} */
    public function preset(string $key): array
    {
        $preset = config("opportunity_review.demo_presets.{$key}");

        if (! is_array($preset)
            || ! is_string(Arr::get($preset, 'label'))
            || ! is_string(Arr::get($preset, 'full_description'))
            || ! is_array(Arr::get($preset, 'overrides'))) {
            abort(422, 'The selected demonstration preset is invalid.');
        }

        /** @var array{label: string, full_description: string, overrides: array<string, mixed>} $preset */
        return $preset;
    }
}
