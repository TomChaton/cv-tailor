<?php

namespace Tests\Feature\MasterCv;

use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\InspectMasterCv;
use App\MasterCv\MasterCvState;
use App\Observability\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\TemporaryFiles;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class InspectMasterCvTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use TemporaryFiles;

    public function test_it_reports_what_the_file_parses_to(): void
    {
        $report = $this->inspect()->handle($this->fixturePath());

        $this->assertSame($this->fixturePath(), $report->path);
        $this->assertSame(MasterCvState::Readable, $report->state);
        $this->assertTrue($report->wasRead());
        $this->assertSame($this->parseFixture()->hash(), $report->version);
        $this->assertSame($this->parseFixture()->counts(), $report->counts);
        $this->assertNotNull($report->modified);
    }

    public function test_it_reads_the_configured_cv_when_given_no_path(): void
    {
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/minimal.md')]);

        $this->assertSame($this->fixturePath('master-cv/minimal.md'), $this->inspect()->handle()->path);
    }

    public function test_a_cv_that_passes_lint_becomes_the_version_a_revision_could_point_at(): void
    {
        $report = $this->inspect()->handle($this->fixturePath());

        $this->assertTrue($report->passes());
        $this->assertSame([], $report->problemsByCheck());
        $this->assertDatabaseHas('master_cv_versions', ['hash' => $report->version]);
    }

    public function test_inspecting_an_unchanged_file_twice_reuses_the_version_already_stored(): void
    {
        $this->inspect()->handle($this->fixturePath());
        $this->inspect()->handle($this->fixturePath());

        $this->assertDatabaseCount('master_cv_versions', 1);
    }

    /**
     * The gate generation applies. Reporting on a file is never a reason to
     * record it as something a CV could be generated from.
     */
    public function test_a_cv_that_fails_lint_does_not_become_a_version(): void
    {
        $report = $this->inspect()->handle($this->fixturePath('master-cv/broken-missing-id.md'));

        $this->assertFalse($report->passes());
        $this->assertDatabaseCount('master_cv_versions', 0);
    }

    public function test_it_groups_problems_under_the_check_that_raised_them(): void
    {
        $report = $this->inspect()->handle($this->fixturePath('master-cv/broken-duplicate-id.md'));

        $this->assertSame(['ids'], array_keys($report->problemsByCheck()));
        $this->assertSame(42, $report->problemsByCheck()['ids'][0]->line);
    }

    public function test_it_keeps_notes_apart_from_problems(): void
    {
        $report = $this->inspect()->handle($this->fixturePath());

        $this->assertSame([], $report->problemsByCheck());
        $this->assertSame(['block_labels'], array_keys($report->notesByCheck()));
        $this->assertCount(3, $report->notes());
    }

    public function test_a_path_with_no_file_at_it_is_reported_rather_than_parsed(): void
    {
        $report = $this->inspect()->handle($this->temporaryPath('nothing-here.md'));

        $this->assertSame(MasterCvState::NotFound, $report->state);
        $this->assertFalse($report->wasRead());
        $this->assertFalse($report->passes());
        $this->assertSame([], $report->problemsByCheck());
        $this->assertNull($report->version);
        $this->assertSame(0, $report->missingIds());
    }

    /**
     * Docker invents an empty directory when a bind mount's source is not
     * there, so this is what a wrong host path looks like from inside the
     * container.
     */
    public function test_a_directory_at_the_path_is_reported_as_one(): void
    {
        mkdir($this->temporaryPath('master-cv.md'));

        $report = $this->inspect()->handle($this->temporaryPath('master-cv.md'));

        $this->assertSame(MasterCvState::Directory, $report->state);
        $this->assertFalse($report->wasRead());
    }

    public function test_it_offers_the_ids_the_cv_is_missing(): void
    {
        $report = $this->inspect()->handle($this->fixturePath('master-cv/broken-missing-id.md'));

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
        $this->assertSame(0, $this->inspect()->handle($this->fixturePath())->missingIds());
    }

    public function test_it_logs_the_parse_with_its_counts(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->inspect()->handle($this->fixturePath());

        $line = $log->first('master_cv.parsed');

        $this->assertNotNull($line);
        $this->assertSame(4, $line['context']['roles']);
        $this->assertSame(44, $line['context']['skills']);
        $this->assertFalse($log->has('master_cv.lint_failed'));
    }

    public function test_it_logs_a_failed_lint_as_counts_per_check(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->inspect()->handle($this->fixturePath('master-cv/broken-missing-id.md'));

        $line = $log->first('master_cv.lint_failed');

        $this->assertNotNull($line);
        $this->assertSame(1, $line['context']['problems']);
        $this->assertSame(['ids' => 1], $line['context']['by_check']);
        $this->assertFalse($log->has('master_cv.parsed'));
    }

    private function inspect(): InspectMasterCv
    {
        return app(InspectMasterCv::class);
    }
}
