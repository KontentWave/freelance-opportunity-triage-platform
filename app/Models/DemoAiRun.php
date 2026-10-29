<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DemoAiRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property array<string, mixed>|null $approved_snapshot
 * @property array<string, mixed>|null $source_snapshot
 * @property CarbonImmutable $reservation_day
 * @property CarbonImmutable $admitted_at
 * @property CarbonImmutable $deadline_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable|null $finalized_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $purged_at
 */
class DemoAiRun extends Model
{
    /** @use HasFactory<DemoAiRunFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approved_snapshot' => 'array', 'source_snapshot' => 'array',
            'admitted_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime', 'reservation_day' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DemoAiRun $run): void {
            foreach (['workspace_id', 'session_hash', 'idempotency_hash', 'version_hash', 'approved_snapshot', 'approved_snapshot_sha256', 'source_snapshot', 'source_snapshot_sha256'] as $field) {
                if ($run->getOriginal($field) !== null && $run->isDirty($field)
                    && ! ($run->purged_at !== null && in_array($field, ['approved_snapshot', 'source_snapshot'], true) && $run->getAttribute($field) === null)) {
                    throw new LogicException('Demo AI run identity and snapshots are immutable.');
                }
            }
        });
    }
}
