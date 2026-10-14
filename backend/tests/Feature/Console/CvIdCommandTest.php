<?php

namespace Tests\Feature\Console;

use App\Models\MasterCv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\TemporaryFiles;
use Tests\TestCase;

class CvIdCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use TemporaryFiles;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = $this->temporaryPath('master-cv-with-ids.md');
    }

    /**
     * The CV as it would be with every ID written, from a stored CV that has
     * none.
     */
    public function test_it_writes_the_whole_document_to_the_path_it_is_given(): void
    {
        $this->storeWithoutIds();

        $this->artisan('cv:id', ['destination' => $this->destination])
            ->expectsOutputToContain('Added 38 IDs: 4 roles, 2 projects, 25 achievements, 7 sub-bullets.')
            ->assertSuccessful();

        $this->assertSame($this->fixture(), (string) file_get_contents($this->destination));
    }

    public function test_it_says_so_when_there_is_nothing_to_add(): void
    {
        $this->storeMasterCv();

        $this->artisan('cv:id', ['destination' => $this->destination])
            ->expectsOutputToContain('Every unit already has an ID.')
            ->assertSuccessful();
    }

    /**
     * It hands the result back rather than writing it into the stored CV: the
     * author reads a diff and adopts what they meant to.
     */
    public function test_it_leaves_the_stored_cv_alone(): void
    {
        $this->storeWithoutIds();
        $before = MasterCv::query()->sole()->markdown;

        $this->artisan('cv:id', ['destination' => $this->destination])->assertSuccessful();

        $this->assertSame($before, MasterCv::query()->sole()->markdown);
    }

    public function test_it_refuses_to_overwrite_a_destination_unless_told_to(): void
    {
        $this->storeWithoutIds();

        file_put_contents($this->destination, 'Something the author wanted to keep.');

        $this->artisan('cv:id', ['destination' => $this->destination])
            ->expectsOutputToContain('Pass --force to overwrite it.')
            ->assertFailed();

        $this->assertSame('Something the author wanted to keep.', (string) file_get_contents($this->destination));

        $this->artisan('cv:id', ['destination' => $this->destination, '--force' => true])
            ->assertSuccessful();

        $this->assertSame($this->fixture(), (string) file_get_contents($this->destination));
    }

    public function test_the_document_goes_to_standard_output_when_no_destination_is_given(): void
    {
        $this->storeWithoutIds();

        $this->artisan('cv:id')
            ->expectsOutputToContain('### Harlequin Transit — Senior Backend Engineer {#harlequin-transit}')
            ->assertSuccessful();
    }

    public function test_it_says_so_plainly_when_no_cv_is_stored(): void
    {
        $this->artisan('cv:id')
            ->expectsOutputToContain('No master CV is stored.')
            ->assertFailed();
    }

    /**
     * The fictional CV with its IDs stripped: a CV written before the IDs
     * existed, which is what this command is for.
     */
    private function storeWithoutIds(): void
    {
        $this->storeMarkdown((string) preg_replace('/ \{#[^}]+\}/', '', $this->fixture()));
    }
}
