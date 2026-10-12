<?php

namespace Tests\Feature\Console;

use App\Models\Posting;
use App\Models\Revision;
use App\Observability\HeartbeatStore;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvStatusCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_says_plainly_when_a_process_has_never_reported(): void
    {
        $this->artisan('cv:status')
            ->expectsOutputToContain('Processes')
            ->expectsOutputToContain('never reported')
            ->assertSuccessful();
    }

    public function test_it_reports_each_process_and_the_age_of_its_heartbeat(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

        $store = app(HeartbeatStore::class);
        $store->record('worker', CarbonImmutable::now());
        $store->record('scheduler', CarbonImmutable::now()->subMinutes(5));

        $this->artisan('cv:status')
            ->doesntExpectOutputToContain('never reported')
            ->expectsOutputToContain('5 minutes ago')
            ->assertSuccessful();
    }

    public function test_it_reports_the_queue(): void
    {
        $this->artisan('cv:status')
            ->expectsOutputToContain('Queue')
            ->expectsOutputToContain('pending jobs')
            ->expectsOutputToContain('failed jobs')
            ->assertSuccessful();
    }

    public function test_it_reports_a_readable_master_cv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'master-cv');
        $this->assertIsString($path);
        file_put_contents($path, "# Fictional Person\n");
        config(['cv.master_cv.path' => $path]);

        $this->artisan('cv:status')
            ->expectsOutputToContain('Master CV')
            ->expectsOutputToContain(basename($path))
            ->expectsOutputToContain('readable')
            ->expectsOutputToContain('modified')
            ->assertSuccessful();

        unlink($path);
    }

    public function test_it_reports_a_master_cv_that_is_not_there(): void
    {
        config(['cv.master_cv.path' => '/var/lib/cv-tailor/nothing-here.md']);

        $this->artisan('cv:status')
            ->expectsOutputToContain('nothing-here.md')
            ->expectsOutputToContain('not found')
            ->doesntExpectOutputToContain('modified')
            ->assertSuccessful();
    }

    /**
     * Docker creates an empty directory when a bind mount's source is missing,
     * so a wrong `MASTER_CV_HOST_PATH` arrives in the container looking like
     * this. It must not read as a present, readable CV.
     */
    public function test_it_reports_a_directory_as_a_directory(): void
    {
        $path = sys_get_temp_dir().'/master-cv-'.bin2hex(random_bytes(6));
        mkdir($path);
        config(['cv.master_cv.path' => $path]);

        $this->artisan('cv:status')
            ->expectsOutputToContain('a directory, not a file')
            ->doesntExpectOutputToContain('modified')
            ->assertSuccessful();

        rmdir($path);
    }

    public function test_it_reports_a_master_cv_it_may_not_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'master-cv');
        $this->assertIsString($path);
        file_put_contents($path, "# Fictional Person\n");
        chmod($path, 0o000);

        if (is_readable($path)) {
            // Tests run as root in some containers, where a mode of 000 still
            // reads. Nothing to assert there.
            unlink($path);
            $this->markTestSkipped('This user can read a file with no permissions.');
        }

        config(['cv.master_cv.path' => $path]);

        $this->artisan('cv:status')
            ->expectsOutputToContain('not readable')
            ->doesntExpectOutputToContain('modified')
            ->assertSuccessful();

        unlink($path);
    }

    public function test_it_reports_the_version_the_master_cv_parses_to_and_that_it_passes_lint(): void
    {
        config(['cv.master_cv.path' => $this->fixturePath()]);

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('version', $output);
        $this->assertStringContainsString($this->parseFixture()->hash(), $output);
        $this->assertStringContainsString('passing', $output);
    }

    public function test_it_reports_a_master_cv_that_would_be_refused(): void
    {
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/broken-missing-id.md')]);

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $this->assertStringContainsString('1 problem: run php artisan cv:lint', Artisan::output());
    }

    /**
     * An empty state has to be visible rather than absent: a status missing
     * from the report cannot be told apart from one that has been renamed.
     */
    public function test_it_reports_every_posting_and_revision_status_on_an_empty_database(): void
    {
        $command = $this->artisan('cv:status')
            ->expectsOutputToContain('Postings')
            ->expectsOutputToContain('Revisions');

        // `ready` is both a posting status and a revision status, and one
        // expectation per distinct word is all this asserts.
        $statuses = array_unique([...PostingStatus::values(), ...RevisionStatus::values()]);

        foreach ($statuses as $status) {
            $command->expectsOutputToContain($status);
        }

        $command->assertSuccessful();
    }

    public function test_the_new_sections_leave_the_ones_above_them_alone(): void
    {
        Posting::factory()->create();
        Revision::factory()->create();

        $this->artisan('cv:status')
            ->expectsOutputToContain('Processes')
            ->expectsOutputToContain('Queue')
            ->expectsOutputToContain('Master CV')
            ->expectsOutputToContain('Postings')
            ->expectsOutputToContain('Revisions')
            ->assertSuccessful();
    }
}
