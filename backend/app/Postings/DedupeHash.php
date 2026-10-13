<?php

namespace App\Postings;

/**
 * What makes capture idempotent.
 *
 * The same role appears on several boards and the extension may retry a send it
 * never saw the answer to, so a capture is identified by what it says rather
 * than by where it was found. The URL is deliberately not part of it: the same
 * posting under two URLs is one posting.
 *
 * An agency repost with the client anonymised carries a different company and
 * will not match. That is accepted: the alternative is a looser hash that folds
 * two genuinely different postings into one.
 */
final readonly class DedupeHash
{
    public static function for(PostingCapture $capture): string
    {
        $parts = array_map(
            self::normalise(...),
            [$capture->title ?? '', $capture->company ?? '', $capture->extractedText],
        );

        // A unit separator rather than whitespace: it cannot appear in any of
        // the normalised parts, so no shuffling of words across the boundary
        // between two parts can produce the same hash.
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Case and whitespace differ between boards for the same posting, and
     * neither changes what the posting says.
     */
    private static function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
