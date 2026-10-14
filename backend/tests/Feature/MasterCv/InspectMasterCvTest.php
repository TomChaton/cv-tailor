<?php

namespace Tests\Feature\MasterCv;

use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\InspectMasterCv;
use App\Observability\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MasterCvFixtures;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class InspectMasterCvTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_reports_what_the_stored_cv_parses_to(): void
    {
        $this->storeMasterCv();

        $report = $this->inspect()->handle();

        $this->assertTrue($report->stored);
        $this->assertSame($this->fixture(), $report->markdown);
        $this->assertSame($this->parseFixture()->hash(), $report->version);
        $this->assertSame($this->parseFixture()->counts(), $report->counts);
        $this->assertNotNull($report->saved);
    }

    /**
     * A fresh installation. The ordinary first state, not a fault.
     */
    public function test_nothing_stored_is_reported_as_nothing_stored(): void
    {
        $report = $this->inspect()->handle();

        $this->assertFalse($report->stored);
        $this->assertSame('', $report->markdown);
        $this->assertFalse($report->passes());
        $this->assertSame([], $report->problemsByCheck());
        $this->assertNull($report->version);
        $this->assertNull($report->saved);
        $this->assertSame(0, $report->missingIds());
    }

    /**
     * A `master_cv_versions` row is what a generation made of the CV. Looking
     * at the CV, or saving it, must leave nothing behind that a revision could
     * claim it was built from.
     */
    public function test_inspecting_a_cv_records_no_version(): void
    {
        $this->storeMasterCv();

        $report = $this->inspect()->handle();

        $this->assertTrue($report->passes());
        $this->assertSame([], $report->problemsByCheck());
        $this->assertDatabaseCount('master_cv_versions', 0);
    }

    public function test_it_groups_problems_under_the_check_that_raised_them(): void
    {
        $this->storeMasterCv('master-cv/broken-duplicate-id.md');

        $report = $this->inspect()->handle();

        $this->assertFalse($report->passes());
        $this->assertSame(['ids'], array_keys($report->problemsByCheck()));
        $this->assertSame(42, $report->problemsByCheck()['ids'][0]->line);
    }

    public function test_it_keeps_notes_apart_from_problems(): void
    {
        $this->storeMasterCv();

        $report = $this->inspect()->handle();

        $this->assertSame([], $report->problemsByCheck());
        $this->assertSame(['block_labels'], array_keys($report->notesByCheck()));
        $this->assertCount(3, $report->notes());
    }

    public function test_it_offers_the_ids_the_cv_is_missing(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $report = $this->inspect()->handle();

        $this->assertSame(1, $report->missingIds());
        $this->assertSame(
            (new AssignMasterCvIds($this->parser()))
                ->handle($this->fixture('master-cv/broken-missing-id.md'))
                ->content,
            $report->ids?->content,
        );
    }

    public function test_a_cv_with_every_id_already_on_it_has_none_to_add(): void
    {
        $this->storeMasterCv();

        $this->assertSame(0, $this->inspect()->handle()->missingIds());
    }

    public function test_it_logs_a_failed_lint_as_counts_per_check(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->inspect()->handle();

        $line = $log->first('master_cv.lint_failed');

        $this->assertNotNull($line);
        $this->assertSame(1, $line['context']['problems']);
        $this->assertSame(['ids' => 1], $line['context']['by_check']);
    }

    public function test_a_cv_that_passes_lint_is_not_logged_about(): void
    {
        $this->storeMasterCv();

        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->inspect()->handle();

        $this->assertFalse($log->has('master_cv.lint_failed'));
        $this->assertFalse($log->has('master_cv.parsed'));
    }

    private function inspect(): InspectMasterCv
    {
        return app(InspectMasterCv::class);
    }
}
