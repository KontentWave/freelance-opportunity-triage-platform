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
 * @property string|null $enrichment_id
 * @property TriageRecommendation $human_label
 * @property string|null $reason_code
 * @property string|null $notes
 * @property string|null $outcome
 * @property string $sample_kind
 * @property-read OpportunityEvaluation $evaluation
 * @property-read OpportunityEnrichment|null $enrichment
 */
class OpportunityReview extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id',
        'evaluation_id',
        'enrichment_id',
        'human_label',
        'reason_code',
        'notes',
        'outcome',
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

    public function enrichment(): BelongsTo
    {
        return $this->belongsTo(OpportunityEnrichment::class, 'enrichment_id');
    }
}
