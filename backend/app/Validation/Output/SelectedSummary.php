<?php

namespace App\Validation\Output;

/**
 * The profile summary the output wrote.
 *
 * Always free text, so it always has to cite what it draws on. There is no
 * verbatim form of a summary: the master CV has no summary for it to repeat.
 */
final readonly class SelectedSummary
{
    /**
     * @param  list<IdReference>  $supports
     */
    public function __construct(
        public string $text,
        public array $supports,
    ) {}
}
