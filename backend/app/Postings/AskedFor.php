<?php

namespace App\Postings;

/**
 * What the owner asked for when they captured a posting.
 *
 * The decision that a posting is worth a CV is made on the job board, in the
 * same glance as the decision to keep the posting at all, so a capture carries
 * it. One field with one value per choice, rather than a flag each: two flags
 * would let a capture say it wanted nothing generated and a cover letter with
 * it, which means nothing.
 *
 * `Save` is what a capture that says nothing asked for, so a caller that knows
 * nothing about this field behaves as the owner keeping a posting for later.
 */
enum AskedFor: string
{
    /** Keep the posting. It waits at `captured` until the owner says more. */
    case Save = 'save';

    /** Generate a CV for it, which is the review UI's Want decision. */
    case Cv = 'cv';

    /** The same, and record that a cover letter was asked for as well. */
    case CvAndCoverLetter = 'cv_and_cover_letter';

    /**
     * Whether this is a decision to spend: the posting becomes `wanted` and the
     * next sweep gives it a revision.
     */
    public function includesCv(): bool
    {
        return $this !== self::Save;
    }

    public function includesCoverLetter(): bool
    {
        return $this === self::CvAndCoverLetter;
    }
}
