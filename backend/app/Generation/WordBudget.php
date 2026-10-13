<?php

namespace App\Generation;

/**
 * The target length, as a number of words.
 *
 * Pages are what the CV's author thinks in and words are what a model can aim
 * at, so the policy's target pages are converted here.
 *
 * The number only has to be close. The budget is a target stated in the
 * prompt, never a limit: structured outputs cannot express a length, and
 * truncating generated output would cut a claim in half and leave the half
 * that reads as finished.
 */
final class WordBudget
{
    /**
     * Roughly what a densely set CV page carries.
     */
    public const WORDS_PER_PAGE = 500;

    public static function forPages(int $pages): int
    {
        return max(1, $pages) * self::WORDS_PER_PAGE;
    }
}
