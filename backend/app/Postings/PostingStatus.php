<?php

namespace App\Postings;

/**
 * Where a captured posting has got to.
 *
 * `captured → wanted → queued → ready → reviewed → applied`, plus `discarded`
 * from anywhere. The list doubles as an application tracker, which is why the
 * tail of it records what the owner did rather than what the pipeline did.
 *
 * **`wanted` is where the owner decides to spend.** Capturing a posting is
 * free and speculative and generating a CV for one is neither, so nothing but
 * the owner moves a posting off `captured`.
 */
enum PostingStatus: string
{
    case Captured = 'captured';

    case Wanted = 'wanted';

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
