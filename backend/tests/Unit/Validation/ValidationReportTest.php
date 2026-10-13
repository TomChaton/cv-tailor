<?php

namespace Tests\Unit\Validation;

use App\Validation\Finding;
use App\Validation\Severity;
use App\Validation\ValidationReport;
use PHPUnit\Framework\TestCase;

class ValidationReportTest extends TestCase
{
    /**
     * "Validated with no findings" and "never validated" have to be
     * distinguishable, or a revision nobody checked would render like one that
     * passed.
     */
    public function test_an_absent_report_is_not_an_empty_one(): void
    {
        $this->assertNull(ValidationReport::fromStored(null));

        $empty = ValidationReport::fromStored([]);

        $this->assertNotNull($empty);
        $this->assertTrue($empty->passes());
        $this->assertSame([], $empty->toArray());
    }

    public function test_only_a_blocking_finding_stops_a_revision(): void
    {
        $this->assertTrue((new ValidationReport([$this->advisory('word_budget')]))->passes());
        $this->assertFalse((new ValidationReport([$this->blocking('do_not_claim')]))->passes());
    }

    public function test_it_counts_per_check_and_per_severity(): void
    {
        $report = new ValidationReport([
            $this->blocking('ids_resolve'),
            $this->blocking('ids_resolve'),
            $this->advisory('word_budget'),
        ]);

        $this->assertSame(['ids_resolve' => 2, 'word_budget' => 1], $report->countsByCheck());
        $this->assertSame(['blocking' => 2, 'advisory' => 1], $report->countsBySeverity());
    }

    /**
     * A severity absent from the report cannot be told apart from one that has
     * been renamed.
     */
    public function test_a_severity_nothing_was_found_at_is_still_reported(): void
    {
        $this->assertSame(
            ['blocking' => 0, 'advisory' => 0],
            (new ValidationReport([]))->countsBySeverity(),
        );
    }

    public function test_the_blocking_checks_are_named_once_and_in_order(): void
    {
        $report = new ValidationReport([
            $this->blocking('versions'),
            $this->blocking('do_not_claim'),
            $this->blocking('versions'),
            $this->advisory('word_budget'),
        ]);

        $this->assertSame(['do_not_claim', 'versions'], $report->blockingChecks());
    }

    public function test_blocking_findings_are_read_first(): void
    {
        $report = new ValidationReport([$this->advisory('word_budget'), $this->blocking('versions')]);

        $this->assertSame('versions', $report->findings[0]->check);
    }

    public function test_a_stored_finding_survives_a_round_trip(): void
    {
        $report = new ValidationReport([
            new Finding('versions', Severity::Blocking, 'summary.text', ['acme'], 'Not recorded.'),
        ]);

        $restored = ValidationReport::fromStored($report->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($report->toArray(), $restored->toArray());
    }

    private function blocking(string $check): Finding
    {
        return Finding::blocking($check, null, [], 'Something.');
    }

    private function advisory(string $check): Finding
    {
        return Finding::advisory($check, null, [], 'Something.');
    }
}
