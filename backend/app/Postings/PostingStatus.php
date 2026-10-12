<?php

namespace App\Postings;

/**
 * Where a captured posting has got to.
 *
 * `captured → queued → ready → reviewed → applied`, plus `discarded` from
 * anywhere. The list doubles as an application tracker, which is why the tail
 * of it records what the owner did rather than what the pipeline did.
 */
enum PostingStatus: string
{
    case Captured = 'captured';

    case Queued = 'queued';

    case Ready = 'ready';

    case Reviewed = 'reviewed';

    case Applied = 'applied';

    case Discarded = 'discarded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
