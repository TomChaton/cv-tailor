<?php

namespace Tests\Feature\Console;

use App\Rendering\PdfConversionFailed;
use App\Rendering\PdfConverter;
use App\Rendering\Testing\FakePdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvPreviewCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destination = tempnam(sys_get_temp_dir(), 'cv-preview').'.pdf';
    }

    protected function tearDown(): void
    {
        if (is_file($this->destination)) {
            unlink($this->destination);
        }

        parent::tearDown();
    }

    public function test_it_renders_the_whole_master_cv_to_the_destination(): void
    {
        $this->artisan('cv:preview', ['destination' => $this->destination, '--cv' => $this->fixturePath()])
            ->assertSuccessful();

        $this->assertFileExists($this->destination);

        $html = $this->converter()->lastHtml();

        $this->assertStringContainsString('Jordan Avery', $html);
        // Every role the policy does not drop, and every selectable bullet of
        // each: the longest document the template is ever asked for.
        $this->assertStringContainsString('Harlequin Transit', $html);
        $this->assertStringContainsString('Quayside Charts', $html);
        $this->assertStringNotContainsString('Larkspur Media', $html);
        $this->assertStringNotContainsString('TODO', $html);
    }

    public function test_it_defaults_to_the_stored_master_cv(): void
    {
        $this->storeMasterCv();

        $this->artisan('cv:preview', ['destination' => $this->destination])->assertSuccessful();

        $this->assertFileExists($this->destination);
    }

    public function test_it_says_so_plainly_when_no_cv_is_stored_and_none_is_given(): void
    {
        $this->artisan('cv:preview', ['destination' => $this->destination])
            ->expectsOutputToContain('No master CV is stored.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->destination);
    }

    /**
     * The same rule generation follows: a CV with duplicate or missing IDs
     * produces a document nobody can resolve back to a fact.
     */
    public function test_it_refuses_a_cv_that_does_not_pass_lint(): void
    {
        $this->artisan('cv:preview', [
            'destination' => $this->destination,
            '--cv' => $this->fixturePath('master-cv/broken-duplicate-id.md'),
        ])->expectsOutputToContain('does not pass cv:lint')->assertFailed();

        $this->assertFileDoesNotExist($this->destination);
    }

    public function test_it_refuses_a_cv_it_cannot_read(): void
    {
        $this->artisan('cv:preview', [
            'destination' => $this->destination,
            '--cv' => $this->fixturePath('master-cv/nothing-here.md'),
        ])->expectsOutputToContain('cannot be read')->assertFailed();
    }

    public function test_it_reports_a_converter_failure_rather_than_writing_half_a_file(): void
    {
        $this->converter()->fail(PdfConversionFailed::status(503));

        $this->artisan('cv:preview', [
            'destination' => $this->destination,
            '--cv' => $this->fixturePath(),
        ])->expectsOutputToContain('503')->assertFailed();

        $this->assertFileDoesNotExist($this->destination);
    }

    private function converter(): FakePdfConverter
    {
        /** @var FakePdfConverter $converter */
        $converter = $this->app->make(PdfConverter::class);

        return $converter;
    }
}
