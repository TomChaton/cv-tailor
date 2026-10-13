<?php

namespace App\Generation;

use App\Models\MasterCvVersion;

/**
 * What one run of the queueing step did.
 *
 * The master CV version is null when there was nothing to queue, because a run
 * with no work does not read the CV at all.
 */
final readonly class QueueOutcome
{
    private function __construct(
        public int $postings,
        public ?int $masterCvVersionId,
    ) {}

    public static function nothingWaiting(): self
    {
        return new self(0, null);
    }

    public static function queued(int $postings, MasterCvVersion $version): self
    {
        return new self($postings, $version->id);
    }
}
