<?php

namespace App\Domain\Triage\Support;

use InvalidArgumentException;
use JsonException;

final class CanonicalJson
{
    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        try {
            return json_encode(
                self::sortRecursively($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('Value cannot be encoded as canonical JSON.');
        }
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortRecursively(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $nestedValue) {
            $value[$key] = self::sortRecursively($nestedValue);
        }

        return $value;
    }
}
