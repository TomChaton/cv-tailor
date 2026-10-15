<?php

namespace App\Postings;

use App\Models\Posting;

/**
 * Records that the owner asked for a cover letter for a posting.
 *
 * **Nothing generates one.** The column is the record of the request and
 * nothing else: a capture that asked for a letter would otherwise be a button
 * whose decision was thrown away, and a posting captured now is already marked
 * for whatever writes one later.
 *
 * It is a fact about the posting rather than about one of its revisions — the
 * owner asked for a letter for this job, and that stays true across
 * regeneration.
 *
 * The request is only ever recorded, never withdrawn: a later capture of the
 * same posting that asks for less has not unasked for the letter.
 */
class RecordCoverLetterRequest
{
    public function handle(Posting $posting): void
    {
        // Conditional on the column, so a posting that already carries the
        // request is not written again — and a capture repeated by the
        // extension's retry queue costs nothing the second time.
        Posting::query()
            ->whereKey($posting->getKey())
            ->where('cover_letter_requested', false)
            ->update(['cover_letter_requested' => true]);
    }
}
