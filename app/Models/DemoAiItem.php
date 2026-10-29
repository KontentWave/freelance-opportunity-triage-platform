<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DemoAiItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property array<string, mixed>|null $input_snapshot
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $retry_at
 */
class DemoAiItem extends Model
{
    /** @use HasFactory<DemoAiItemFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input_snapshot' => 'array', 'result' => 'array', 'retry_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (DemoAiItem $item): void {
            foreach (['run_id', 'workspace_id', 'ordinal', 'source_namespace', 'source_id', 'mailbox_key', 'uidvalidity', 'uid', 'body_sha256', 'input_sha256', 'raw_sha256', 'input_snapshot', 'result'] as $field) {
                if ($item->getOriginal($field) !== null && $item->isDirty($field)) {
                    throw new LogicException('Demo AI item identity, input and validated result are immutable.');
                }
            }
        });
    }
}
