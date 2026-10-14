<?php

namespace Tests\Feature\Console;

use App\Models\MasterCv;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvImportCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_populates_an_installation_that_has_no_cv(): void
    {
        $this->artisan('cv:import', ['path' => $this->fixturePath()])
            ->expectsOutputToContain('Imported')
            ->assertSuccessful();

        $this->assertSame($this->fixture(), MasterCv::query()->sole()->markdown);
    }

    /**
     * The stored CV may be the one that has since been edited, and a file on
     * disk is no evidence that it is not.
     */
    public function test_it_refuses_an_installation_that_already_has_one(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        $this->artisan('cv:import', ['path' => $this->fixturePath()])
            ->expectsOutputToContain('Pass --force to replace it.')
            ->assertFailed();

        $this->assertSame($this->fixture('master-cv/minimal.md'), MasterCv::query()->sole()->markdown);
    }

    public function test_force_replaces_the_cv_that_is_stored(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        $this->artisan('cv:import', ['path' => $this->fixturePath(), '--force' => true])
            ->assertSuccessful();

        $this->assertSame($this->fixture(), MasterCv::query()->sole()->markdown);
        $this->assertDatabaseCount('master_cv', 1);
    }

    public function test_it_says_so_plainly_when_the_file_cannot_be_read(): void
    {
        $this->artisan('cv:import', ['path' => $this->fixturePath('master-cv/nothing-here.md')])
            ->expectsOutputToContain('cannot be read')
            ->assertFailed();

        $this->assertDatabaseCount('master_cv', 0);
    }

    /**
     * An import is a save like any other: a CV with problems in it lands, and
     * the command says so rather than leaving it reading as ready to use.
     */
    public function test_a_cv_that_fails_lint_is_imported_and_reported(): void
    {
        $this->assertSame(
            Command::SUCCESS,
            Artisan::call('cv:import', ['path' => $this->fixturePath('master-cv/broken-missing-id.md')]),
        );

        $output = Artisan::output();

        $this->assertStringContainsString('Imported', $output);
        $this->assertStringContainsString('1 problem', $output);
        $this->assertStringContainsString('cv:lint', $output);

        $this->assertSame(
            $this->fixture('master-cv/broken-missing-id.md'),
            MasterCv::query()->sole()->markdown,
        );
    }

    /**
     * An import writes no version: a version is what a generation used.
     */
    public function test_an_import_records_no_version(): void
    {
        $this->artisan('cv:import', ['path' => $this->fixturePath()])->assertSuccessful();

        $this->assertDatabaseCount('master_cv_versions', 0);
    }
}
