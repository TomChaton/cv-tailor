<?php

namespace App\MasterCv\Lint;

use App\MasterCv\ParsedMasterCv;

/**
 * One check `cv:lint` makes against a parsed master CV.
 *
 * A check reads the parse and says what is wrong with the file. It never
 * looks at generated output: that is the validators' job, on different input.
 */
interface Check
{
    /**
     * A short, stable name. Problem counts are logged against it, so renaming
     * one renames an observability signal.
     */
    public function name(): string;

    /**
     * @return list<Finding>
     */
    public function run(ParsedMasterCv $cv): array;
}
