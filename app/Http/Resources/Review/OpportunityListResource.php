<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OpportunityListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource->getAttribute('displayed_result');

        return [
            'id' => $this->resource->id,
            'evaluation_id' => $this->resource->getAttribute('displayed_evaluation_id'),
            'current_enrichment_id' => null,
            'title' => $this->resource->title,
            'hourly_min' => $this->resource->hourly_min,
            'hourly_max' => $this->resource->hourly_max,
            'currency' => $this->resource->currency,
            'posted_on' => $this->resource->posted_on?->toDateString(),
            'recommendation' => $this->resource->getAttribute('displayed_recommendation') ?? 'UNSCORED',
            'score' => $this->resource->getAttribute('displayed_score'),
            'basis' => $result === null ? null : 'Email evidence',
            'missing_fields' => is_array($result) ? ($result['missing_fields'] ?? []) : [],
            'stale' => (bool) $this->resource->getAttribute('displayed_stale'),
            'reviewed' => $this->resource->getAttribute('current_review_id') !== null,
        ];
    }
}
