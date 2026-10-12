<?php

namespace App\Observability\Status;

/**
 * One block of `php artisan cv:status`.
 *
 * Anything with a state machine reports through a section of its own, listed
 * in `config/cv.php`. Adding one never changes the sections already there.
 */
interface StatusSection
{
    public function heading(): string;

    /**
     * @return array<string, string> Label to value, in display order.
     */
    public function rows(): array;
}
