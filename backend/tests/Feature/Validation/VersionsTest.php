<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\Versions;
use App\Validation\Severity;

class VersionsTest extends CheckTestCase
{
    public function test_the_versions_the_master_cv_records_pass(): void
    {
        $this->assertPasses($this->selection());
    }

    /**
     * One character of difference and three years of experience the owner does
     * not have.
     */
    public function test_a_version_the_role_never_ran_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('northgate-tickets', [
            $this->reworded(
                'northgate-tickets.3',
                'Moved venue search onto Elasticsearch 8.',
                ['northgate-tickets.3'],
            ),
        ]));

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertStringContainsString('Elasticsearch 8', $findings[0]->message);
        $this->assertStringContainsString('Northgate Tickets', $findings[0]->message);
    }

    /**
     * The master CV's own rule: a version it does not give is not to be added.
     */
    public function test_a_version_added_where_the_role_gives_none_fails(): void
    {
        $this->assertFails($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.4.3',
                'Dead-letter replay on the queues behind it, on Redis 7.',
                ['harlequin-transit.4.3'],
            ),
        ]));
    }

    /**
     * A role's own bullets are part of what it records, not only its tech
     * stack: the stack says Elasticsearch 8 and the bullet says the upgrade
     * came from 7.
     */
    public function test_a_version_recorded_in_the_roles_own_bullets_passes(): void
    {
        $this->assertPasses($this->selectionWithItems('harlequin-transit', [
            $this->reworded(
                'harlequin-transit.5.1',
                'Moved search from Elasticsearch 7 to Elasticsearch 8 with no downtime.',
                ['harlequin-transit.5.1'],
            ),
        ]));
    }

    public function test_a_version_from_another_role_fails(): void
    {
        $this->assertFails($this->selectionWithItems('pelham-civic-data', [
            $this->reworded(
                'pelham-civic-data.2',
                'Replaced Apache Solr with MySQL 5.7 full-text search.',
                ['pelham-civic-data.2'],
            ),
        ]));
    }

    /**
     * A version in the summary belongs to no role, so the whole document is
     * what it is checked against. A version the CV records nowhere is wrong
     * wherever it appears, and the summary is the field most worth checking.
     */
    public function test_a_version_in_the_summary_is_checked_against_the_document(): void
    {
        $findings = $this->assertFails(
            $this->selectionWithSummary('Backend engineer working in PHP 9 on dispatch systems.'),
        );

        $this->assertSame('summary.text', $findings[0]->field);
        $this->assertStringContainsString('PHP 9', $findings[0]->message);
    }

    public function test_a_version_the_document_records_passes_in_the_summary(): void
    {
        $this->assertPasses(
            $this->selectionWithSummary('Backend engineer working in PHP 8.3 on dispatch systems.'),
        );
    }

    protected function check(): Check
    {
        return $this->app->make(Versions::class);
    }
}
