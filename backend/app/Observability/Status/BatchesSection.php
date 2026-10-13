<?php

namespace App\Observability\Status;

use App\Batching\LlmBatchState;
use App\Batching\SubmitterHalt;
use App\Models\LlmBatch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batches, and whether the submitter is running at all.
 *
 * The submitter's state is the section's first line. A stopped submitter has
 * to be the first thing a reader sees rather than something inferred from a
 * pile of pending revisions that are not moving.
 */
class BatchesSection extends CountByStatusSection
{
    public function __construct(private readonly SubmitterHalt $halt) {}

    public function heading(): string
    {
        return 'Batches';
    }

    public function rows(): array
    {
        return [
            'submitter' => $this->submitter(),
            ...$this->halted(),
            ...parent::rows(),
            'oldest open' => $this->oldestOpen(),
        ];
    }

    protected function query(): Builder
    {
        return LlmBatch::query();
    }

    protected function statuses(): array
    {
        return LlmBatchState::values();
    }

    private function submitter(): string
    {
        return $this->halt->isHalted()
            ? 'halted: run php artisan cv:batch-resume'
            : 'running';
    }

    /**
     * What halted it, on a row of its own. One short line saying the submitter
     * has stopped and how to start it is worth more than one long line that
     * also says why.
     *
     * @return array<string, string>
     */
    private function halted(): array
    {
        if (! $this->halt->isHalted()) {
            return [];
        }

        $at = $this->halt->haltedAt();
        $ids = implode(', ', array_map(static fn (int $id): string => (string) $id, $this->halt->haltedBy()));

        return [
            'halted after' => $at === null
                ? 'batches '.$ids
                : sprintf('batches %s, %s', $ids, $at->diffForHumans()),
        ];
    }

    /**
     * The age of the batch that has been waiting longest to be collected,
     * which is the number an uncollected batch shows up in.
     */
    private function oldestOpen(): string
    {
        $oldest = LlmBatch::query()->open()->first();

        if ($oldest === null) {
            return 'none';
        }

        return sprintf(
            'batch %d, submitted %s',
            $oldest->id,
            $oldest->submitted_at->diffForHumans(),
        );
    }
}
