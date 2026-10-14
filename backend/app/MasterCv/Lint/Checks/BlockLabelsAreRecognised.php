<?php

namespace App\MasterCv\Lint\Checks;

use App\MasterCv\Lint\Check;
use App\MasterCv\Lint\Finding;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\RoleParser;

/**
 * Reports labelled blocks this tool does not know, as information.
 *
 * Their bullets are treated as achievements, which is almost always what the
 * author meant. It is reported rather than rejected so that a one-off label is
 * a thing the author chose and not a thing the tool refuses.
 *
 * The note names the labels that are known, because a reader told only that
 * theirs is unknown cannot choose one that is. It offers no fix and no button:
 * there is nothing wrong here to put right.
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
                    '"%s" is not a block label this tool knows, so its bullets are read as achievements. It knows %s.',
                    $block['label'],
                    $this->recognised(),
                ));
            }
        }

        return $findings;
    }

    private function recognised(): string
    {
        $labels = RoleParser::RECOGNISED_LABELS;
        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }
}
