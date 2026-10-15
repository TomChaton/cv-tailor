<?php

namespace App\Review;

use App\Validation\ValidationReport;

/**
 * Everything the review screen has to say about one revision.
 *
 * `findings` is null where the revision has never been validated and empty
 * where it was validated and found nothing. The two are not the same thing,
 * and nothing may read them as one.
 */
final readonly class RevisionReview
{
    /**
     * @param  list<Requirement>  $requirements
     */
    public function __construct(
        public array $requirements,
        public ?ValidationReport $findings,
        public ReviewNotes $notes,
    ) {}
}
