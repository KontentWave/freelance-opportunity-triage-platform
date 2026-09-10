<?php

namespace App\Domain\Triage\Data;

use App\Domain\Triage\Support\CanonicalJson;
use App\Domain\Triage\Support\SkillNormalizer;
use InvalidArgumentException;

final readonly class ScoringProfile
{
    private const TOP_LEVEL_KEYS = [
        'schema_version',
        'label',
        'purpose',
        'minimum_hourly_usd',
        'preferred_skills',
        'minimum_client_rating',
        'weights',
        'thresholds',
    ];

    private const WEIGHT_KEYS = [
        'skill_match',
        'rate',
        'payment_verified',
        'client_rating',
    ];

    private const THRESHOLD_KEYS = ['skip_below', 'apply_at'];

    /**
     * @param  list<string>  $preferredSkills
     * @param  array{skill_match: int, rate: int, payment_verified: int, client_rating: int}  $weights
     * @param  array{skip_below: int, apply_at: int}  $thresholds
     * @param  array<string, mixed>  $definition
     */
    private function __construct(
        public int $schemaVersion,
        public string $label,
        public string $purpose,
        public string $minimumHourlyUsd,
        public array $preferredSkills,
        public string $minimumClientRating,
        public array $weights,
        public array $thresholds,
        public array $definition,
        public string $canonicalJson,
        public string $version,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        self::requireExactKeys($definition, self::TOP_LEVEL_KEYS);

        $schemaVersion = $definition['schema_version'];
        $label = $definition['label'];
        $purpose = $definition['purpose'];
        $minimumHourlyUsd = $definition['minimum_hourly_usd'];
        $preferredSkills = $definition['preferred_skills'];
        $minimumClientRating = $definition['minimum_client_rating'];
        $weights = $definition['weights'];
        $thresholds = $definition['thresholds'];

        if ($schemaVersion !== 1
            || ! is_string($label)
            || trim($label) === ''
            || mb_strlen($label) > 80
            || ! is_string($purpose)
            || ! in_array($purpose, ['demo', 'personal'], true)
            || ! is_string($minimumHourlyUsd)
            || ! self::isValidDecimal($minimumHourlyUsd, 99_999_999_99)
            || ! is_string($minimumClientRating)
            || ! self::isValidDecimal($minimumClientRating, 500)
            || ! is_array($preferredSkills)
            || ! array_is_list($preferredSkills)
            || ! is_array($weights)
            || ! is_array($thresholds)) {
            throw new InvalidArgumentException('Invalid scoring profile.');
        }

        if (count($preferredSkills) < 1 || count($preferredSkills) > 50) {
            throw new InvalidArgumentException('Invalid scoring profile.');
        }

        $normalizedSkills = SkillNormalizer::normalize($preferredSkills, rejectEmpty: true, rejectDuplicates: true);
        self::requireExactKeys($weights, self::WEIGHT_KEYS);
        self::requireExactKeys($thresholds, self::THRESHOLD_KEYS);

        foreach (self::WEIGHT_KEYS as $key) {
            if (! is_int($weights[$key]) || $weights[$key] < 1 || $weights[$key] > 100) {
                throw new InvalidArgumentException('Invalid scoring profile.');
            }
        }

        if (array_sum($weights) !== 100
            || ! is_int($thresholds['skip_below'])
            || ! is_int($thresholds['apply_at'])
            || $thresholds['skip_below'] < 0
            || $thresholds['skip_below'] >= $thresholds['apply_at']
            || $thresholds['apply_at'] > 100) {
            throw new InvalidArgumentException('Invalid scoring profile.');
        }

        $normalizedDefinition = [
            'schema_version' => $schemaVersion,
            'label' => $label,
            'purpose' => $purpose,
            'minimum_hourly_usd' => $minimumHourlyUsd,
            'preferred_skills' => $normalizedSkills,
            'minimum_client_rating' => $minimumClientRating,
            'weights' => [
                'skill_match' => $weights['skill_match'],
                'rate' => $weights['rate'],
                'payment_verified' => $weights['payment_verified'],
                'client_rating' => $weights['client_rating'],
            ],
            'thresholds' => [
                'skip_below' => $thresholds['skip_below'],
                'apply_at' => $thresholds['apply_at'],
            ],
        ];
        $canonicalJson = CanonicalJson::encode($normalizedDefinition);

        return new self(
            schemaVersion: $schemaVersion,
            label: $label,
            purpose: $purpose,
            minimumHourlyUsd: $minimumHourlyUsd,
            preferredSkills: $normalizedSkills,
            minimumClientRating: $minimumClientRating,
            weights: $normalizedDefinition['weights'],
            thresholds: $normalizedDefinition['thresholds'],
            definition: $normalizedDefinition,
            canonicalJson: $canonicalJson,
            version: hash('sha256', $canonicalJson),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function requireExactKeys(array $value, array $expectedKeys): void
    {
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException('Invalid scoring profile.');
        }
    }

    private static function isValidDecimal(string $value, int $maximumCents): bool
    {
        if (preg_match('/^(0|[1-9]\d{0,7})\.\d{2}$/D', $value) !== 1) {
            return false;
        }

        [$whole, $fraction] = explode('.', $value);
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $cents > 0 && $cents <= $maximumCents;
    }
}
