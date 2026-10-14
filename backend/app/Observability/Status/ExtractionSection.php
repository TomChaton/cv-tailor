<?php

namespace App\Observability\Status;

use App\Models\Posting;
use RuntimeException;

/**
 * How many postings are still waiting for a model to read them.
 *
 * Extraction is a queued job per capture, so a worker that has stopped or a
 * provider that is refusing shows up as a count that does not come down —
 * visible here, where the owner already looks, rather than inferred from a
 * postings list whose rows are all blank.
 */
class ExtractionSection implements StatusSection
{
    private const COLUMN = 'extracted_at';

    public function heading(): string
    {
        return 'Extraction';
    }

    public function rows(): array
    {
        $this->guardColumnExists();

        return [
            'awaiting extraction' => (string) Posting::query()->whereNull(self::COLUMN)->count(),
            'extracted' => (string) Posting::query()->whereNotNull(self::COLUMN)->count(),
        ];
    }

    /**
     * SQLite reads a quoted identifier that matches no column as a string
     * literal, so a count on a column that is not there is answered with a zero
     * rather than an error — and this is the command somebody reads to find out
     * whether anything is happening. The question is asked of the schema before
     * it is asked of the rows.
     */
    private function guardColumnExists(): void
    {
        $model = new Posting;

        if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), self::COLUMN)) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s has no column %s, so %s cannot count by it.',
            $model->getTable(),
            self::COLUMN,
            static::class,
        ));
    }
}
