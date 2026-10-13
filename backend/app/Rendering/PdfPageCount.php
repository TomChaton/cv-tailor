<?php

namespace App\Rendering;

/**
 * How many pages a PDF has.
 *
 * A byte size alone hides the failure worth catching: a one-page CV where three
 * were expected weighs about what a one-page CV should. The count is read from
 * the file's own page objects rather than by starting a renderer, because the
 * only reason to know it is to put it in a log line.
 *
 * It answers null when the structure is not one it can read, which is honest
 * about not knowing rather than reporting a zero that looks like an empty
 * document.
 */
final class PdfPageCount
{
    public static function of(string $pdf): ?int
    {
        // `/Type /Page` once per page, and not `/Pages`, which is the tree
        // above them.
        $pages = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);

        if (is_int($pages) && $pages > 0) {
            return $pages;
        }

        // A writer that puts its page objects in a compressed object stream
        // still declares the tree's own count outside it.
        preg_match_all('#/Count\s+(\d+)#', $pdf, $matches);

        /** @var list<int> $counts */
        $counts = array_map(intval(...), $matches[1]);

        return $counts === [] ? null : max($counts);
    }
}
