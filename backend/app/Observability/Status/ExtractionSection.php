<?php

namespace App\Observability\Status;

use App\Models\Posting;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * How many postings there is nothing to tailor a CV against yet, and why.
 *
 * The first count is over the job description rather than over `extracted_at`,
 * because the description is what the sweep requires: a posting extraction
 * answered without one is not queued either, and a count that only noticed the
 * ones a worker had not reached would read zero while nothing generated. So
 * one line answers "why has nothing generated?" — a worker that has stopped, a
 * provider that is refusing and a page no job could be read from are all a
 * count that does not come down.
 *
 * The two beneath it break that count down, because the answer to a posting
 * extraction has not reached is to look at the worker, and the answer to one
 * extraction read and found no job in is to discard it. Those are different
 * actions, and nothing else in the system tells them apart.
 */
class ExtractionSection implements StatusSection
{
    private const DESCRIPTION = 'description';

    private const EXTRACTED_AT = 'extracted_at';

    public function heading(): string
    {
        return 'Extraction';
    }

    public function rows(): array
    {
        $this->guardColumnsExist();

        // The two indented labels are inside the count above them rather than
        // beside it, so the owner is never asked to add two lines up or to
        // wonder which of them the sweep is waiting on.
        return [
            'awaiting a job description' => $this->count(
                Posting::query()->whereNull(self::DESCRIPTION),
            ),
            '  extraction has not run' => $this->count(
                Posting::query()->whereNull(self::EXTRACTED_AT),
            ),
            '  extraction found none' => $this->count(
                Posting::query()->whereNull(self::DESCRIPTION)->whereNotNull(self::EXTRACTED_AT),
            ),
            'with a job description' => $this->count(
                Posting::query()->whereNotNull(self::DESCRIPTION),
            ),
        ];
    }

    /**
     * @param  Builder<Posting>  $query
     */
    private function count(Builder $query): string
    {
        return (string) $query->count();
    }

    /**
     * SQLite reads a quoted identifier that matches no column as a string
     * literal, so a condition on a column that is not there is answered
     * without an error — and this is the command somebody reads to find out
     * whether anything is happening. The question is asked of the schema
     * before it is asked of the rows.
     */
    private function guardColumnsExist(): void
    {
        $model = new Posting;
        $schema = $model->getConnection()->getSchemaBuilder();

        foreach ([self::DESCRIPTION, self::EXTRACTED_AT] as $column) {
            if ($schema->hasColumn($model->getTable(), $column)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s has no column %s, so %s cannot count by it.',
                $model->getTable(),
                $column,
                static::class,
            ));
        }
    }
}
