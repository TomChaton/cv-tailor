<?php

namespace Tests\Feature\Console;

use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvIdCommandTest extends TestCase
{
    use MasterCvFixtures;

    private string $source;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = sys_get_temp_dir().'/cv-id-'.bin2hex(random_bytes(6));
        mkdir($directory);

        $this->source = $directory.'/master-cv.md';
        $this->destination = $directory.'/master-cv-with-ids.md';

        file_put_contents($this->source, (string) preg_replace('/ \{#[^}]+\}/', '', $this->fixture()));
    }

    protected function tearDown(): void
    {
        foreach ([$this->source, $this->destination] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir(dirname($this->source));

        parent::tearDown();
    }

    public function test_it_writes_the_whole_document_to_the_path_it_is_given(): void
    {
        $this->artisan('cv:id', ['source' => $this->source, 'destination' => $this->destination])
            ->expectsOutputToContain('Added 38 IDs: 4 roles, 2 projects, 25 achievements, 7 sub-bullets.')
            ->assertSuccessful();

        $this->assertSame($this->fixture(), (string) file_get_contents($this->destination));
    }

    public function test_it_says_so_when_there_is_nothing_to_add(): void
    {
        file_put_contents($this->source, $this->fixture());

        $this->artisan('cv:id', ['source' => $this->source, 'destination' => $this->destination])
            ->expectsOutputToContain('Every unit already has an ID.')
            ->assertSuccessful();
    }

    /**
     * The master CV is mounted read-only by decision, and this command is not
     * the reason to undo that.
     */
    public function test_it_refuses_to_write_back_over_its_source(): void
    {
        $this->artisan('cv:id', ['source' => $this->source, 'destination' => $this->source])
            ->expectsOutputToContain('Writing back over the source is not allowed.')
            ->assertFailed();

        $this->assertStringNotContainsString('{#', (string) file_get_contents($this->source));
    }

    public function test_it_refuses_to_overwrite_a_destination_unless_told_to(): void
    {
        file_put_contents($this->destination, 'Something the author wanted to keep.');

        $this->artisan('cv:id', ['source' => $this->source, 'destination' => $this->destination])
            ->expectsOutputToContain('Pass --force to overwrite it.')
            ->assertFailed();

        $this->assertSame('Something the author wanted to keep.', (string) file_get_contents($this->destination));

        $this->artisan('cv:id', [
            'source' => $this->source,
            'destination' => $this->destination,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame($this->fixture(), (string) file_get_contents($this->destination));
    }

    public function test_the_document_goes_to_standard_output_when_no_destination_is_given(): void
    {
        $this->artisan('cv:id', ['source' => $this->source])
            ->expectsOutputToContain('### Harlequin Transit — Senior Backend Engineer {#harlequin-transit}')
            ->assertSuccessful();
    }

    public function test_it_says_so_plainly_when_the_source_cannot_be_read(): void
    {
        $this->artisan('cv:id', ['source' => '/var/lib/cv-tailor/nothing-here.md'])
            ->expectsOutputToContain('cannot be read')
            ->assertFailed();
    }
}
