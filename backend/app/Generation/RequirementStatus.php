<?php

namespace App\Generation;

/**
 * What the master CV has to say about one of the job's requirements.
 *
 * An enum for the same reason the priority is one: the output schema builds
 * its values from here, so the three states exist in one place.
 *
 * The order is what needs attention first. A gap is the thing the owner has to
 * weigh before sending anything; a requirement that is met needs reading only
 * to check the evidence behind it.
 */
enum RequirementStatus: string
{
    case Met = 'met';

    case Partial = 'partial';

    case Gap = 'gap';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Where this status sorts against the others, lowest first.
     */
    public function attention(): int
    {
        return match ($this) {
            self::Gap => 0,
            self::Partial => 1,
            self::Met => 2,
        };
    }
}
