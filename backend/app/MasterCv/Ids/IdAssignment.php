<?php

namespace App\MasterCv\Ids;

/**
 * The document with IDs added, and how many were added per kind.
 */
final readonly class IdAssignment
{
    /**
     * @param  array<string, int>  $added
     */
    public function __construct(
        public string $content,
        public array $added,
    ) {}

    public function total(): int
    {
        return array_sum($this->added);
    }
}
