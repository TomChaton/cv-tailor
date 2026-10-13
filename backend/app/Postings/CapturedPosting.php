<?php

namespace App\Postings;

/**
 * The outcome of a capture: which posting now holds it, and whether this
 * capture is what created it.
 *
 * The two are separate because the extension has two things to say — saved,
 * against already saved — and the status code is where that distinction lives.
 *
 * It carries the posting's identity rather than the posting, because that is
 * all a caller answering a capture needs. Keeping Eloquent out of the response
 * path also keeps the OpenAPI document generated from these types alone: a
 * contract that could only be generated against a migrated database would be a
 * contract nobody could regenerate.
 */
final readonly class CapturedPosting
{
    public function __construct(
        public int $id,
        public PostingStatus $status,
        public bool $isNew,
    ) {}
}
