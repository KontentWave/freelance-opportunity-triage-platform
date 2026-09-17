<?php

namespace App\Models;

use App\Domain\Triage\Enums\TriageRecommendation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $opportunity_id
 * @property string $engine_version
 * @property string $profile_version
 * @property string $input_sha256
 * @property array<string, mixed> $profile_snapshot
 * @property array<string, mixed> $input_snapshot
 * @property array{recommendation: string, score: int, contributions: list<array{rule: string, state: string, points: int, maximum_points: int, reason_code: string, explanation: string}>, missing_fields: list<string>, hard_exclusions: list<string>, decision_reason_code: string, decision_explanation: string, manual_review_required: bool} $result
 * @property TriageRecommendation $recommendation
 * @property int $score
 * @property-read Opportunity $opportunity
 * @property-read OpportunityReview|null $review
 */
class OpportunityEvaluation extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'opportunity_id',
        'engine_version',
        'profile_version',
        'input_sha256',
        'profile_snapshot',
        'input_snapshot',
        'result',
        'recommendation',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'profile_snapshot' => 'array',
            'input_snapshot' => 'array',
            'result' => 'array',
            'recommendation' => TriageRecommendation::class,
            'score' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OpportunityEvaluation $evaluation): void {
            if (($evaluation->result['recommendation'] ?? null) !== $evaluation->recommendation->value
                || ($evaluation->result['score'] ?? null) !== $evaluation->score) {
                throw new LogicException('Stored evaluation summary must match its result.');
            }
        });
        static::updating(fn () => throw new LogicException('Opportunity evaluations are immutable.'));
        static::deleting(fn () => throw new LogicException('Opportunity evaluations are immutable.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(OpportunityReview::class, 'evaluation_id');
    }

    public function enrichments(): HasMany
    {
        return $this->hasMany(OpportunityEnrichment::class, 'evaluation_id');
    }
}
