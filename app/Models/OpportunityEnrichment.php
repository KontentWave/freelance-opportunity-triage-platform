<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $opportunity_id
 * @property string $evaluation_id
 * @property int $revision
 * @property string $full_description
 * @property array<string, mixed> $overrides
 * @property array<string, mixed> $input_snapshot
 * @property array<string, mixed> $result
 * @property string $payload_sha256
 * @property string $input_sha256
 */
class OpportunityEnrichment extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'opportunity_id',
        'evaluation_id',
        'revision',
        'full_description',
        'overrides',
        'input_snapshot',
        'result',
        'payload_sha256',
        'input_sha256',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'overrides' => 'array',
            'input_snapshot' => 'array',
            'result' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Opportunity enrichments are immutable.'));
        static::deleting(fn () => throw new LogicException('Opportunity enrichments are immutable.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(OpportunityEvaluation::class, 'evaluation_id');
    }
}
