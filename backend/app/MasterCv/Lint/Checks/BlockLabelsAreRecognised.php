<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\ParsedMasterCv;

/**
 * Reports labelled blocks this tool does not know, as information.
 *
 * Their bullets are treated as achievements, which is almost always what the
 * author meant. It is reported rather than rejected so that a one-off label is
 * a thing the author chose and not a thing the tool refuses.
 */
class BlockLabelsAreRecognised implements Check
{
    public function name(): string
    {
        return 'block_labels';
    }

    public function run(ParsedMasterCv $cv): array
    {
        $findings = [];

        foreach ($cv->allRoles() as $role) {
            foreach ($role->unknownBlocks as $block) {
                $findings[] = Finding::note($this->name(), $block['line'], sprintf(
                    '"%s" is not a block label this tool knows, so its bullets are read as achievements.',
                    $block['label'],
                ));
            }
        }

        return $findings;
    }
}
