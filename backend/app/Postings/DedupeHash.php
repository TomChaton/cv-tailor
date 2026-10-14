<?php

namespace App\Postings;

/**
 * What makes capture idempotent.
 *
 * The hash is over the normalised page text and over nothing a model produced.
 * A hash derived from model output would make a retried capture hash
 * differently from the first attempt, and capture would stop being idempotent
 * — which is the property the extension's whole retry queue rests on.
 *
 * The cost is that the same role on two boards is now two postings rather than
 * one. The owner discards one, which is visible and cheap, where a broken retry
 * is neither.
 *
 * The URL is deliberately not part of it: the same page under two URLs is one
 * capture, and a retry the extension never saw the answer to is the same
 * capture rather than a second one.
 */
final readonly class DedupeHash
{
    public static function for(PostingCapture $capture): string
    {
        return hash('sha256', self::normalise($capture->pageText));
    }

    /**
     * Case and whitespace differ between two readings of one page, and neither
     * changes what the page says.
     */
    private static function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
