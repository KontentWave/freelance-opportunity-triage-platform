<?php

namespace App\Http\Resources\Review;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OpportunityDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $list = (new OpportunityListResource($this->resource))->resolve($request);
        $canonicalUrl = $this->safeCanonicalUrl($this->resource->canonical_url);

        return $list + [
            'canonical_url' => $canonicalUrl,
            'excerpt' => $this->resource->excerpt,
            'contract_type' => $this->resource->contract_type,
            'estimated_duration' => $this->resource->estimated_duration,
            'payment_verified' => $this->resource->payment_verified,
            'client_rating' => $this->resource->client_rating,
            'client_spend_usd' => $this->resource->client_spend_usd,
            'client_spend_approximate' => $this->resource->client_spend_approximate,
            'client_country' => $this->resource->client_country,
            'skills' => $this->resource->skills->pluck('name')->all(),
            'hidden_skill_count' => $this->resource->hidden_skill_count,
            'profile' => $this->profile(),
            'email_input' => $this->resource->getAttribute('displayed_input_snapshot'),
            'current_input' => $this->resource->getAttribute('displayed_current_input'),
            'email_result' => $this->resource->getAttribute('displayed_result'),
            'displayed_result' => $this->resource->getAttribute('displayed_result'),
            'current_review' => $this->resource->getAttribute('current_review_id') === null
                ? null
                : ['id' => $this->resource->getAttribute('current_review_id')],
        ];
    }

    /** @return array{label: mixed, version: mixed}|null */
    private function profile(): ?array
    {
        $profile = $this->resource->getAttribute('displayed_profile_snapshot');

        if (! is_array($profile)) {
            return null;
        }

        return [
            'label' => $profile['label'] ?? null,
            'version' => $this->resource->getAttribute('displayed_profile_version'),
        ];
    }

    private function safeCanonicalUrl(mixed $url): ?string
    {
        if (! is_string($url) || preg_match('#^https://www\.upwork\.com/jobs/~\d+$#D', $url) !== 1) {
            return null;
        }

        return $url;
    }
}
