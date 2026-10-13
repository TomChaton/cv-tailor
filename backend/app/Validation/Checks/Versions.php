<?php

namespace App\Validation\Checks;

use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\FindingMessages;
use App\Validation\ValidationContext;
use App\Validation\VersionFacts;

/**
 * A version number in the output is the one the master CV records.
 *
 * A version upgrade is a fabrication that reads as a typo. "PHP 8" where the
 * role ran 7.4 is one character of difference and three years of experience
 * the owner does not have, and it is exactly what a model reaching for a job
 * description's keywords will write.
 *
 * A version is checked against **the role it is claimed for**: a role that ran
 * 7.4 cannot borrow the 8.3 another role ran. A version in the summary belongs
 * to no role, so it is checked against the whole document — a version the CV
 * records nowhere is wrong wherever it appears, and the summary is the field
 * most worth checking.
 *
 * A technology the source pins no version to refuses every version, because
 * the master CV's rule is that a version it does not give is not to be added.
 */
class Versions implements Check
{
    public function __construct(private readonly FindingMessages $messages) {}

    public function name(): string
    {
        return 'versions';
    }

    public function run(ValidationContext $context): array
    {
        $findings = [];
        $summary = $context->selection->summary;

        if ($summary !== null) {
            $findings = [...$findings, ...$this->findingsIn(
                $context,
                $summary->text,
                VersionFacts::forDocument($context->cv),
                'summary.text',
                null,
            )];
        }

        foreach ($context->selection->items() as [$selected, $item]) {
            $role = $selected->id === null ? null : $context->index->role($selected->id);

            if ($item->text === null || $role === null) {
                continue;
            }

            $findings = [...$findings, ...$this->findingsIn(
                $context,
                $item->text,
                VersionFacts::forRole($role),
                $item->field.'.text',
                $role->employer,
                $item->id,
            )];
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function findingsIn(
        ValidationContext $context,
        string $text,
        VersionFacts $facts,
        string $field,
        ?string $role,
        ?string $id = null,
    ): array {
        $findings = [];

        foreach ($context->technologies->detect($text) as $technology) {
            foreach ($technology->versionsIn($text) as $version) {
                if ($facts->records($technology, $version)) {
                    continue;
                }

                $findings[] = Finding::blocking(
                    $this->name(),
                    $field,
                    $id === null ? [] : [$id],
                    $this->messages->get(
                        $role === null ? 'versions.not_recorded' : 'versions.not_recorded_for_role',
                        ['term' => $technology->name, 'version' => $version, 'role' => $role ?? ''],
                    ),
                );
            }
        }

        return $findings;
    }
}
