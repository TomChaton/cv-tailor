<?php

namespace App\Validation;

/**
 * How much a finding costs the revision it was raised against.
 *
 * The two are kept apart because they answer different questions. A blocking
 * finding says the revision claims something the master CV does not support,
 * so it is never rendered. An advisory finding says the revision is untidy —
 * longer than the policy asks for, or a judgement left unexplained — which is
 * for the owner to weigh, not for code to refuse.
 */
enum Severity: string
{
    case Blocking = 'blocking';

    case Advisory = 'advisory';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
