<?php

namespace App\Domain\DemoAi;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class EvaluationOutput
{
    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $approved
     * @return array<string, mixed>
     */
    public function schema(array $input, array $approved): array
    {
        $evidence = $this->object([
            'field' => [
                'type' => 'string', 'enum' => array_keys($this->evidenceFields($input, $approved)),
                'description' => 'Use the exact namespaced identifier: input.<field> for alert fields or profile.<field> for public profile fields. Bare field names and null fields are not evidence.',
            ],
            'quote' => [
                'type' => 'string', 'minLength' => 1, 'maxLength' => 160,
                'description' => 'An exact substring of the cited field, not a paraphrase. For non-string values, quote their JSON representation.',
            ],
        ]);
        $reason = $this->object([
            'criterion_id' => ['type' => 'string', 'enum' => ApprovedDemoInputs::CRITERIA],
            'explanation' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 240],
            'evidence' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => $evidence],
        ]);

        return $this->object([
            'source_id' => ['type' => 'string'],
            'recommendation' => ['type' => 'string', 'enum' => ['APPLY', 'MAYBE', 'SKIP']],
            'reasons' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 4, 'items' => $reason],
            'missing_information' => [
                'type' => 'array', 'maxItems' => 8,
                'items' => ['type' => 'string', 'enum' => $this->missingInformationIdentifiers($input)],
                'description' => 'Use distinct input field names or rubric criterion IDs from this enum, not evidence namespaces or prose. Identify every material requirement that needs clarification.',
            ],
        ]);
    }

    /** @param array<string, mixed> $value
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $approved
     */
    public function validate(array $value, array $input, array $approved): void
    {
        $validator = Validator::make(['value' => $value], [
            'value' => ['required', 'array:source_id,recommendation,reasons,missing_information'],
            'value.source_id' => ['required', 'string', Rule::in([$input['source_id']])],
            'value.recommendation' => ['required', Rule::in(['APPLY', 'MAYBE', 'SKIP'])],
            'value.reasons' => ['required', 'array', 'list', 'min:1', 'max:4'],
            'value.reasons.*' => ['required', 'array:criterion_id,explanation,evidence'],
            'value.reasons.*.criterion_id' => ['required', Rule::in(ApprovedDemoInputs::CRITERIA)],
            'value.reasons.*.explanation' => ['required', 'string', 'max:240'],
            'value.reasons.*.evidence' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'value.reasons.*.evidence.*' => ['required', 'array:field,quote'],
            'value.reasons.*.evidence.*.field' => ['required', 'string'],
            'value.reasons.*.evidence.*.quote' => ['required', 'string', 'max:160'],
            'value.missing_information' => ['present', 'array', 'list', 'max:8'],
            'value.missing_information.*' => ['required', 'string', 'distinct', Rule::in($this->missingInformationIdentifiers($input))],
        ]);
        if ($validator->fails()) {
            throw new DemoAiException('demo_ai.output_invalid');
        }
        $evidenceFields = $this->evidenceFields($input, $approved);
        foreach ($value['reasons'] as $reason) {
            foreach ($reason['evidence'] as $evidence) {
                if (! isset($evidenceFields[$evidence['field']])
                    || ! str_contains($evidenceFields[$evidence['field']], $evidence['quote'])) {
                    throw new DemoAiException('demo_ai.evidence_invalid');
                }
            }
        }
        $this->validateHardConstraints($value, $input);
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $approved
     * @return array<string, string>
     */
    private function evidenceFields(array $input, array $approved): array
    {
        $evidenceFields = [];
        foreach (['input' => $input, 'profile' => $approved['profile']] as $prefix => $fields) {
            foreach ($fields as $field => $content) {
                if ($content !== null) {
                    $evidenceFields[$prefix.'.'.$field] = is_string($content)
                        ? $content : json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $evidenceFields;
    }

    /** @param array<string, mixed> $input
     * @return list<string>
     */
    private function missingInformationIdentifiers(array $input): array
    {
        return array_values(array_unique(array_merge(array_keys($input), ApprovedDemoInputs::CRITERIA)));
    }

    /** @param array<string, mixed> $value
     * @param  array<string, mixed>  $input
     */
    private function validateHardConstraints(array $value, array $input): void
    {
        $description = (string) $input['description'];
        $incompatibilities = [];
        // These are factual, explicit requirements in the approved corpus, not model bands.
        foreach ([
            'availability' => ['Mandatory forty-hour weekly availability, no part-time arrangement', 'The mandatory minimum is twenty-five and cannot be reduced to twenty'],
            'devices' => ['physical Android tablet that you already own'],
            'geography' => ['Slovakia-based applicants are not eligible'],
            'essential_capability' => ['independently lead low-level Rust lock-free memory optimization with established specialist production expertise'],
        ] as $criterion => $phrases) {
            foreach ($phrases as $phrase) {
                if (stripos($description, $phrase) !== false) {
                    $incompatibilities[$criterion] = $phrase;
                }
            }
        }
        foreach ($input['required_devices'] ?? [] as $device) {
            if (stripos($device, 'tablet') !== false) {
                $incompatibilities['devices'] = $device;
            }
        }
        $unknowns = [];
        if ($input['hours_per_week_max'] === null) {
            $unknowns[] = 'availability';
        }
        if ($input['required_devices'] === null) {
            $unknowns[] = 'devices';
        }
        if ($input['eligible_locations'] === null) {
            $unknowns[] = 'geography';
        }
        if (stripos($description, 'not yet defined') !== false || stripos($description, 'Scope, required devices') !== false) {
            $unknowns[] = 'scope_fit';
        }
        $materialUnknown = $unknowns !== [];
        if (($value['recommendation'] === 'APPLY' && ($incompatibilities !== [] || $materialUnknown))
            || ($value['recommendation'] === 'SKIP' && $incompatibilities === [])) {
            throw new DemoAiException('demo_ai.constraint_conflict');
        }
        if ($value['recommendation'] === 'SKIP') {
            $supported = false;
            foreach ($value['reasons'] as $reason) {
                if (! isset($incompatibilities[$reason['criterion_id']])) {
                    continue;
                }
                foreach ($reason['evidence'] as $evidence) {
                    if ($evidence['field'] === 'input.description'
                        && stripos($evidence['quote'], $incompatibilities[$reason['criterion_id']]) !== false) {
                        $supported = true;
                    }
                    if ($reason['criterion_id'] === 'devices' && $evidence['field'] === 'input.required_devices'
                        && stripos($evidence['quote'], $incompatibilities['devices']) !== false) {
                        $supported = true;
                    }
                }
            }
            if (! $supported) {
                throw new DemoAiException('demo_ai.hard_evidence_missing');
            }
        }
        if ($materialUnknown && $incompatibilities === [] && $value['recommendation'] !== 'MAYBE') {
            throw new DemoAiException('demo_ai.constraint_conflict');
        }
        if ($materialUnknown && $incompatibilities === []) {
            $fields = ['availability' => 'hours_per_week_max', 'devices' => 'required_devices', 'geography' => 'eligible_locations', 'scope_fit' => 'description'];
            foreach ($unknowns as $unknown) {
                if (array_intersect([$unknown, $fields[$unknown]], $value['missing_information']) === []) {
                    throw new DemoAiException('demo_ai.missing_information_invalid');
                }
            }
        }
    }

    /** @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
