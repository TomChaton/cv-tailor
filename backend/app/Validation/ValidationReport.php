<?php

namespace App\Validation;

/**
 * What validation found about one revision.
 *
 * **An empty report is not an absent one.** A revision that passed cleanly
 * stores an empty list of findings; a revision that has never been validated
 * stores null. Nothing downstream may treat the two as the same thing, or a
 * revision nobody checked would render like one that passed.
 */
final readonly class ValidationReport
{
    /** @var list<Finding> */
    public array $findings;

    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(array $findings)
    {
        // Blocking findings first: they are what the owner has to act on.
        usort($findings, static fn (Finding $a, Finding $b): int => [$a->blocks() ? 0 : 1, $a->check]
            <=> [$b->blocks() ? 0 : 1, $b->check]);

        $this->findings = $findings;
    }

    /**
     * The stored findings as a report, or null when the revision has never
     * been validated.
     *
     * @param  ?array<array-key, mixed>  $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        $findings = [];

        foreach ($stored as $finding) {
            if (is_array($finding)) {
                $findings[] = Finding::fromArray($finding);
            }
        }

        return new self($findings);
    }

    public function passes(): bool
    {
        return $this->blocking() === [];
    }

    /**
     * @return list<Finding>
     */
    public function blocking(): array
    {
        return $this->atSeverity(Severity::Blocking);
    }

    /**
     * @return list<Finding>
     */
    public function advisory(): array
    {
        return $this->atSeverity(Severity::Advisory);
    }

    /**
     * The checks that blocked, for the warning log line. Names, never
     * messages: a message quotes the CV.
     *
     * @return list<string>
     */
    public function blockingChecks(): array
    {
        $checks = [];

        foreach ($this->blocking() as $finding) {
            $checks[$finding->check] = true;
        }

        $names = array_keys($checks);
        sort($names);

        return $names;
    }

    /**
     * Counts per check, for the log event.
     *
     * @return array<string, int>
     */
    public function countsByCheck(): array
    {
        $counts = [];

        foreach ($this->findings as $finding) {
            $counts[$finding->check] = ($counts[$finding->check] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Counts per severity, with both severities always present: a severity
     * absent from the report cannot be told apart from one that has been
     * renamed.
     *
     * @return array<string, int>
     */
    public function countsBySeverity(): array
    {
        $counts = array_fill_keys(Severity::values(), 0);

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @return list<array{check: string, severity: string, field: ?string, ids: list<string>, message: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Finding $finding): array => $finding->toArray(), $this->findings);
    }

    /**
     * @return list<Finding>
     */
    private function atSeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->severity === $severity,
        ));
    }
}
