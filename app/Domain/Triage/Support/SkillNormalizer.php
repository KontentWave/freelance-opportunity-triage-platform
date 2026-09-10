<?php

namespace App\Domain\Triage\Support;

use InvalidArgumentException;

final class SkillNormalizer
{
    /**
     * @param  list<mixed>  $skills
     * @return list<string>
     */
    public static function normalize(array $skills, bool $rejectEmpty, bool $rejectDuplicates): array
    {
        $normalized = [];

        foreach ($skills as $skill) {
            if (! is_string($skill)) {
                throw new InvalidArgumentException('Invalid skill value.');
            }

            $collapsed = preg_replace('/[\s\p{Z}]+/u', ' ', $skill);

            if ($collapsed === null) {
                throw new InvalidArgumentException('Invalid skill value.');
            }

            $normalizedSkill = mb_strtolower(trim($collapsed));

            if ($normalizedSkill === '') {
                if ($rejectEmpty) {
                    throw new InvalidArgumentException('Invalid skill value.');
                }

                continue;
            }

            if (mb_strlen($normalizedSkill) > 100) {
                throw new InvalidArgumentException('Invalid skill value.');
            }

            $normalized[] = $normalizedSkill;
        }

        $unique = array_values(array_unique($normalized, SORT_STRING));

        if ($rejectDuplicates && count($unique) !== count($normalized)) {
            throw new InvalidArgumentException('Invalid skill value.');
        }

        sort($unique, SORT_STRING);

        return $unique;
    }
}
