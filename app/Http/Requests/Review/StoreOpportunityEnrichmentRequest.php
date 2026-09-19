<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreOpportunityEnrichmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (config('opportunity_review.mode') === 'demo') {
            return [
                'evaluation_id' => ['required', 'string', 'ulid'],
                'expected_enrichment_id' => ['present', 'nullable', 'string', 'ulid'],
                'preset_key' => ['required', Rule::in(array_keys(config('opportunity_review.demo_presets', [])))],
            ];
        }

        return [
            'evaluation_id' => ['required', 'string', 'ulid'],
            'expected_enrichment_id' => ['present', 'nullable', 'string', 'ulid'],
            'full_description' => ['required', 'string', 'min:1', 'max:20000'],
            'overrides' => ['present', 'array'],
            'overrides.contract_type' => ['sometimes', 'nullable', Rule::in(['hourly'])],
            'overrides.currency' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{3}$/D'],
            'overrides.hourly_max' => ['sometimes', 'nullable', 'decimal:2', 'min:0', 'max:99999999.99'],
            'overrides.skills' => ['sometimes', 'array', 'max:50'],
            'overrides.skills.*' => ['string', 'max:100'],
            'overrides.hidden_skill_count' => ['sometimes', 'integer', 'between:0,100'],
            'overrides.payment_verified' => ['sometimes', 'nullable', 'boolean:strict'],
            'overrides.client_rating' => ['sometimes', 'nullable', 'decimal:2', 'between:0,5'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (mb_strlen($this->getContent(), '8bit') > 65_536) {
            abort(413, 'The enrichment request is too large.');
        }

        $demoMode = config('opportunity_review.mode') === 'demo';
        $allowed = $demoMode
            ? ['evaluation_id', 'expected_enrichment_id', 'preset_key']
            : ['evaluation_id', 'expected_enrichment_id', 'full_description', 'overrides'];
        $unknown = array_diff(array_keys($this->all()), $allowed);
        $overrides = $this->input('overrides');
        $unknownOverrides = ! $demoMode && is_array($overrides)
            ? array_diff(array_keys($overrides), ['contract_type', 'currency', 'hourly_max', 'skills', 'hidden_skill_count', 'payment_verified', 'client_rating'])
            : [];

        if ($unknown !== [] || $unknownOverrides !== []) {
            $field = $unknown !== [] ? (string) reset($unknown) : 'overrides.'.reset($unknownOverrides);
            throw ValidationException::withMessages([$field => 'This field is not supported.']);
        }

        if (! $demoMode && is_string($this->input('full_description'))) {
            $this->merge([
                'full_description' => trim(str_replace(["\r\n", "\r"], "\n", $this->input('full_description'))),
            ]);
        }

        if (! $demoMode && is_array($overrides) && isset($overrides['currency']) && is_string($overrides['currency'])) {
            $overrides['currency'] = mb_strtoupper($overrides['currency']);
            $this->merge(['overrides' => $overrides]);
        }
    }
}
