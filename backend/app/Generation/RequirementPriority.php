<?php

namespace App\Generation;

/**
 * How much one of the job's requirements matters to the employer.
 *
 * An enum rather than two string literals in the output schema, so a priority
 * that exists only in a string cannot be invented — the same reasoning the
 * posting and revision states are enums for.
 *
 * The order is the order the review screen reads them in: a must-have is what
 * decides whether a CV can be sent at all.
 */
enum RequirementPriority: string
{
    case Must = 'must';

    case Nice = 'nice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Where this priority sorts against the others, lowest first.
     */
    public function attention(): int
    {
        return match ($this) {
            self::Must => 0,
            self::Nice => 1,
        };
    }
}
