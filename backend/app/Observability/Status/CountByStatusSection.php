<?php

namespace App\Observability\Status;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A `cv:status` section that counts one table's rows by the column holding
 * their state.
 *
 * Every status is reported, including the ones nothing is in. A state that is
 * simply absent from the output cannot be told apart from one that has been
 * renamed or forgotten about.
 *
 * The column is named by the section rather than defaulted here: tables in
 * this application do not agree on one name for it, and a default is inherited
 * silently by the table that calls it something else.
 */
abstract class CountByStatusSection implements StatusSection
{
    /**
     * @return Builder<covariant Model>
     */
    abstract protected function query(): Builder;

    /**
     * The column this table holds its state in.
     */
    abstract protected function column(): string;

    /**
     * Every status the table's state machine has, in the order it runs.
     *
     * @return list<string>
     */
    abstract protected function statuses(): array;

    public function rows(): array
    {
        $column = $this->column();

        $this->guardColumnExists($column);

        $rows = [];

        // A count per status rather than one grouped query: every status
        // appears in the report whether or not anything is in it, so a query
        // that returns only the occupied ones saves nothing here.
        foreach ($this->statuses() as $status) {
            $rows[$status] = (string) $this->query()->where($column, $status)->count();
        }

        return $rows;
    }

    /**
     * SQLite reads a quoted identifier that matches no column as a string
     * literal, so counting on a column that is not there is answered with a
     * zero rather than an error — and this is the command somebody reads to
     * find out whether anything is happening. The question is asked of the
     * schema before it is asked of the rows.
     */
    private function guardColumnExists(string $column): void
    {
        $model = $this->query()->getModel();
        $table = $model->getTable();

        if ($model->getConnection()->getSchemaBuilder()->hasColumn($table, $column)) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s has no column %s, so %s cannot count by it.',
            $table,
            $column,
            static::class,
        ));
    }
}
