<?php

namespace App\Domain\Triage\Data;

use App\Domain\Triage\Support\CanonicalJson;
use App\Domain\Triage\Support\SkillNormalizer;
use InvalidArgumentException;

final readonly class TriageInput
{
    private const KEYS = [
        'contract_type',
        'currency',
        'hourly_max',
        'skills',
        'hidden_skill_count',
        'payment_verified',
        'client_rating',
    ];

    /**
     * @param  list<string>  $skills
     * @param  array<string, mixed>  $snapshot
     */
    private function __construct(
        public ?string $contractType,
        public ?string $currency,
        public ?string $hourlyMax,
        public array $skills,
        public int $hiddenSkillCount,
        public ?bool $paymentVerified,
        public ?string $clientRating,
        public array $snapshot,
        public string $canonicalJson,
        public string $sha256,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $keys = array_keys($values);
        sort($keys, SORT_STRING);
        $expectedKeys = self::KEYS;
        sort($expectedKeys, SORT_STRING);

        if ($keys !== $expectedKeys
            || (! is_string($values['contract_type']) && $values['contract_type'] !== null)
            || (! is_string($values['currency']) && $values['currency'] !== null)
            || (! is_string($values['hourly_max']) && $values['hourly_max'] !== null)
            || ! is_array($values['skills'])
            || ! array_is_list($values['skills'])
            || ! is_int($values['hidden_skill_count'])
            || $values['hidden_skill_count'] < 0
            || (! is_bool($values['payment_verified']) && $values['payment_verified'] !== null)
            || (! is_string($values['client_rating']) && $values['client_rating'] !== null)) {
            throw new InvalidArgumentException('Invalid triage input.');
        }

        $contractType = self::normalizeOptionalString($values['contract_type']);
        $currency = self::normalizeOptionalString($values['currency']);
        $currency = $currency === null ? null : mb_strtoupper($currency);
        $hourlyMax = self::normalizeDecimal($values['hourly_max']);
        $clientRating = self::normalizeDecimal($values['client_rating']);
        $skills = SkillNormalizer::normalize($values['skills'], rejectEmpty: false, rejectDuplicates: false);
        $snapshot = [
            'contract_type' => $contractType,
            'currency' => $currency,
            'hourly_max' => $hourlyMax,
            'skills' => $skills,
            'hidden_skill_count' => $values['hidden_skill_count'],
            'payment_verified' => $values['payment_verified'],
            'client_rating' => $clientRating,
        ];
        $canonicalJson = CanonicalJson::encode($snapshot);

        return new self(
            contractType: $contractType,
            currency: $currency,
            hourlyMax: $hourlyMax,
            skills: $skills,
            hiddenSkillCount: $values['hidden_skill_count'],
            paymentVerified: $values['payment_verified'],
            clientRating: $clientRating,
            snapshot: $snapshot,
            canonicalJson: $canonicalJson,
            sha256: hash('sha256', $canonicalJson),
        );
    }

    private static function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeDecimal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(0|[1-9]\d{0,7})\.\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid triage input.');
        }

        return $value;
    }
}
