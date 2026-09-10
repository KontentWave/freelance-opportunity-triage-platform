<?php

namespace App\Models;

use App\Domain\Triage\Enums\TriageRecommendation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $evaluation_id
 * @property TriageRecommendation $human_label
 * @property string|null $reason_code
 * @property string $sample_kind
 * @property-read OpportunityEvaluation $evaluation
 */
class OpportunityReview extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'evaluation_id',
        'human_label',
        'reason_code',
        'sample_kind',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'human_label' => TriageRecommendation::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(OpportunityEvaluation::class, 'evaluation_id');
    }
}
