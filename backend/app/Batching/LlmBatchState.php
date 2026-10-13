<?php

namespace App\Batching;

/**
 * Where a submitted batch has got to, in this application's own terms.
 *
 * `ended` and `collected` are separate, and that separation is the point of
 * the table: the provider reports that it has finished, and only we know
 * whether the results were actually taken. Collapsing the two would make an
 * uncollected batch look finished right up until its results expired.
 *
 * The three terminal states say what became of the results rather than only
 * that they were read: a batch every one of whose requests expired has not
 * been collected in any sense worth the word.
 */
enum LlmBatchState: string
{
    case Submitted = 'submitted';

    case Ended = 'ended';

    case Collected = 'collected';

    case Expired = 'expired';

    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether the batch still owes us something: the poller's whole set.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Submitted, self::Ended => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $state): bool => $state->isOpen()));
    }
}
