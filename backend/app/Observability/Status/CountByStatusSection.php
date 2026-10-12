<?php

namespace App\Observability\Status;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A `cv:status` section that counts one table's rows by their status column.
 *
 * Every status is reported, including the ones nothing is in. A state that is
 * simply absent from the output cannot be told apart from one that has been
 * renamed or forgotten about.
 */
abstract class CountByStatusSection implements StatusSection
{
    /**
     * @return Builder<covariant Model>
     */
    abstract protected function query(): Builder;

    /**
     * Every status the table's state machine has, in the order it runs.
     *
     * @return list<string>
     */
    abstract protected function statuses(): array;

    public function rows(): array
    {
        $rows = [];

        // A count per status rather than one grouped query: every status
        // appears in the report whether or not anything is in it, so a query
        // that returns only the occupied ones saves nothing here.
        foreach ($this->statuses() as $status) {
            $rows[$status] = (string) $this->query()->where('status', $status)->count();
        }

        return $rows;
    }
}
