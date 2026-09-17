<?php

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateOpportunityFeedbackRequest extends FormRequest
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
        return [
            'evaluation_id' => ['required', 'string', 'ulid'],
            'enrichment_id' => ['present', 'nullable', 'string', 'ulid'],
            'human_label' => ['required', Rule::in(['APPLY', 'MAYBE', 'SKIP'])],
            'reason_code' => ['present', 'nullable', Rule::in(['fit', 'availability', 'economics', 'client_risk', 'missing_information', 'other'])],
            'notes' => ['present', 'nullable', 'string', 'max:2000'],
            'outcome' => ['present', 'nullable', Rule::in(['not_applied', 'applied', 'in_discussion', 'hired', 'closed'])],
            'sample_kind' => ['required', Rule::in(['demo', 'real'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_keys($this->all()), [
            'evaluation_id',
            'enrichment_id',
            'human_label',
            'reason_code',
            'notes',
            'outcome',
            'sample_kind',
        ]);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                (string) reset($unknown) => 'This field is not supported.',
            ]);
        }
    }
}
