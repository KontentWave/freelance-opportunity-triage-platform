<?php

namespace App\Infrastructure\Triage;

use App\Domain\Triage\Data\ScoringProfile;
use App\Domain\Triage\Enums\TriageErrorCode;
use App\Domain\Triage\Exceptions\TriageException;
use InvalidArgumentException;
use JsonException;

class LocalScoringProfileLoader
{
    private const MAXIMUM_PROFILE_BYTES = 65_536;

    public function load(mixed $profilePath): ScoringProfile
    {
        if (! is_string($profilePath) || trim($profilePath) === '') {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        $path = trim($profilePath);

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || ! is_file($path)
            || ! is_readable($path)) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        $size = filesize($path);

        if (! is_int($size) || $size > self::MAXIMUM_PROFILE_BYTES) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        $contents = file_get_contents($path, false, null, 0, self::MAXIMUM_PROFILE_BYTES + 1);

        if (! is_string($contents) || strlen($contents) > self::MAXIMUM_PROFILE_BYTES) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }

        try {
            $definition = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($definition)) {
                throw new InvalidArgumentException;
            }

            return ScoringProfile::fromArray($definition);
        } catch (JsonException|InvalidArgumentException) {
            throw new TriageException(TriageErrorCode::ProfileInvalid);
        }
    }
}
