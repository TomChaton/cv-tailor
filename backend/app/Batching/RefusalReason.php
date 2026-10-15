<?php

namespace App\Batching;

/**
 * Why the submitter did not send a revision it found waiting.
 *
 * The two are reported separately because they ask different things of the
 * owner: a revision that cannot be built is a master CV to go and fix, and a
 * revision behind a discarded posting is nothing anybody has to do.
 */
enum RefusalReason: string
{
    /**
     * The request builder would not build it — which is what a master CV that
     * no longer lints looks like from here.
     */
    case NotBuildable = 'not_buildable';

    /**
     * Its posting has been discarded, so nobody is waiting for the CV.
     */
    case PostingDiscarded = 'posting_discarded';

    /**
     * Every reason, at nought.
     *
     * A reason absent from a report cannot be told apart from one that has
     * been renamed, so the count carries them all whether or not anything was
     * refused for them.
     *
     * @return array<string, int>
     */
    public static function none(): array
    {
        return array_fill_keys(array_column(self::cases(), 'value'), 0);
    }
}
