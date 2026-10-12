<?php

namespace App\MasterCv\Lint;

/**
 * What lint found, in the order it should be read: by line.
 */
final readonly class LintReport
{
    /** @var list<Finding> */
    public array $findings;

    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(array $findings)
    {
        usort($findings, static fn (Finding $a, Finding $b): int => ($a->line ?? 0) <=> ($b->line ?? 0));

        $this->findings = $findings;
    }

    public function passes(): bool
    {
        return $this->problems() === [];
    }

    /**
     * @return list<Finding>
     */
    public function problems(): array
    {
        return $this->atLevel(FindingLevel::Problem);
    }

    /**
     * @return list<Finding>
     */
    public function notes(): array
    {
        return $this->atLevel(FindingLevel::Note);
    }

    /**
     * Problem counts per check, for the log event. The findings' text quotes
     * the CV and so never goes to a log; the counts say as much as a log line
     * is allowed to.
     *
     * @return array<string, int>
     */
    public function problemsByCheck(): array
    {
        $counts = [];

        foreach ($this->problems() as $problem) {
            $counts[$problem->check] = ($counts[$problem->check] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<Finding>
     */
    private function atLevel(FindingLevel $level): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->level === $level,
        ));
    }
}
