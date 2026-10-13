<?php

namespace App\MasterCv;

use App\MasterCv\Ids\IdAssignment;
use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\LintReport;
use Carbon\CarbonImmutable;

/**
 * What the application makes of the master CV at one moment.
 *
 * It is assembled fresh each time it is asked for, never stored: the file is
 * edited outside the application, so a remembered answer is a stale one.
 *
 * A CV that could not be read has a state and nothing else. Reporting it as a
 * parse with no facts in it would be indistinguishable from an empty CV, and
 * the two want different things doing about them.
 */
final readonly class MasterCvReport
{
    /**
     * @param  array<string, int>|null  $counts
     */
    private function __construct(
        public string $path,
        public MasterCvState $state,
        public ?CarbonImmutable $modified = null,
        public ?string $version = null,
        public ?array $counts = null,
        public ?LintReport $lint = null,
        public ?IdAssignment $ids = null,
    ) {}

    public static function unreadable(string $path, MasterCvState $state): self
    {
        return new self($path, $state);
    }

    public static function of(
        string $path,
        CarbonImmutable $modified,
        ParsedMasterCv $parsed,
        LintReport $lint,
        IdAssignment $ids,
    ): self {
        return new self(
            $path,
            MasterCvState::Readable,
            $modified,
            $parsed->hash(),
            $parsed->counts(),
            $lint,
            $ids,
        );
    }

    public function wasRead(): bool
    {
        return $this->lint !== null;
    }

    public function passes(): bool
    {
        return $this->lint?->passes() ?? false;
    }

    /**
     * Problems under the name of the check that raised them.
     *
     * Grouped because the checks ask different questions of the file, and a
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
