<?php

namespace App\Review;

/**
 * One master CV ID a requirement cites, resolved to the words it names.
 *
 * An ID on its own is not an audit. The owner is checking what the CV claims
 * about them, and that means reading the sentence the model pointed at rather
 * than trusting a reference to it.
 *
 * **`text` falls back to the ID itself** where the master CV version the
 * revision is pinned to does not hold it. Dropping it would hide a citation
 * the validators have something to say about, and a citation nobody can see is
 * worse than an unresolved one.
 */
final readonly class Evidence
{
    public function __construct(
        public string $id,
        public string $text,
    ) {}
}
