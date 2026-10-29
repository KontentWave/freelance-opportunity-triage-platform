<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DemoAiAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $pricing_snapshot
 * @property array<string, mixed> $request_provenance
 * @property array<string, int>|null $usage
 * @property CarbonImmutable $dispatch_day
 * @property CarbonImmutable $dispatched_at
 * @property CarbonImmutable|null $inference_authorized_at
 * @property CarbonImmutable|null $settled_at
 */
class DemoAiAttempt extends Model
{
    /** @use HasFactory<DemoAiAttemptFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pricing_snapshot' => 'array', 'request_provenance' => 'array', 'usage' => 'array',
            'dispatch_day' => 'immutable_date', 'dispatched_at' => 'immutable_datetime', 'settled_at' => 'immutable_datetime',
            'inference_authorized_at' => 'immutable_datetime',
        ];
    }
}
