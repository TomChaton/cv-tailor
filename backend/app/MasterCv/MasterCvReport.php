<?php

namespace App\MasterCv;

use App\MasterCv\Ids\IdAssignment;
use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\LintReport;
use Carbon\CarbonImmutable;

/**
 * What the application makes of the stored master CV.
 *
 * It is assembled fresh each time it is asked for and never kept, so a page
 * that is rendered twice reports the CV as it stands twice.
 *
 * A CV that has never been saved has nothing to report but that. Reporting it
 * as a parse with no facts in it would be indistinguishable from a CV its
 * author had emptied, and the two want different things doing about them.
 *
 * It carries the parse itself as well as the counts taken from it, because a
 * finding is addressed to a unit of the document and a surface that offers to
 * act on one has to reach that unit.
 */
final readonly class MasterCvReport
{
    /**
     * @param  array<string, int>|null  $counts
     */
    private function __construct(
        public bool $stored,
        public string $markdown = '',
        public ?CarbonImmutable $saved = null,
        public ?string $version = null,
        public ?array $counts = null,
        public ?LintReport $lint = null,
        public ?IdAssignment $ids = null,
        public ?ParsedMasterCv $parsed = null,
    ) {}

    /**
     * A fresh installation. The ordinary first state, not a fault.
     */
    public static function none(): self
    {
        return new self(false);
    }

    public static function of(
        string $markdown,
        CarbonImmutable $saved,
        ParsedMasterCv $parsed,
        LintReport $lint,
        IdAssignment $ids,
    ): self {
        return new self(
            true,
            $markdown,
            $saved,
            $parsed->hash(),
            $parsed->counts(),
            $lint,
            $ids,
            $parsed,
        );
    }

    public function passes(): bool
    {
        return $this->lint?->passes() ?? false;
    }

    /**
     * Problems under the name of the check that raised them.
     *
     * Grouped because the checks ask different questions of the CV, and a
     * list that interleaves them reads as one long complaint. Within a group
     * the order is the report's, which is by line.
     *
     * @return array<string, list<Finding>>
     */
    public function problemsByCheck(): array
    {
        return $this->byCheck($this->lint?->problems() ?? []);
    }

    /**
     * @return array<string, list<Finding>>
     */
    public function notesByCheck(): array
    {
        return $this->byCheck($this->notes());
    }

    /**
     * @return list<Finding>
     */
    public function notes(): array
    {
        return $this->lint?->notes() ?? [];
    }

    public function missingIds(): int
    {
        return $this->ids?->total() ?? 0;
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, list<Finding>>
     */
    private function byCheck(array $findings): array
    {
        $grouped = [];

        foreach ($findings as $finding) {
            $grouped[$finding->check][] = $finding;
        }

        return $grouped;
    }
}
