<?php

namespace App\Validation;

/**
 * One check validation makes against a revision's output.
 *
 * A check reads the output beside the master CV version it came from and says
 * what the output claims that the CV does not support. It never decides what
 * happens next: whether a finding stops a revision being rendered is the
 * finding's severity, and collecting them is the validator's job.
 */
interface Check
{
    /**
     * A short, stable name. Finding counts are logged against it, so renaming
     * one renames an observability signal.
     */
    public function name(): string;

    /**
     * @return list<Finding>
     */
    public function run(ValidationContext $context): array;
}
