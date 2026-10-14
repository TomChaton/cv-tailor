<?php

namespace Tests\Feature\Console;

use App\Observability\EventLogger;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvLintCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_passes_a_cv_that_is_fit_to_generate_from(): void
    {
        $this->storeMasterCv();

        $this->artisan('cv:lint')
            ->expectsOutputToContain('Nothing to fix.')
            ->assertSuccessful();
    }

    /**
     * The exit code is what generation and the scheduler rely on, so a failure
     * has to be non-zero as well as legible.
     */
    public function test_it_fails_and_names_the_line_and_the_problem(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $exitCode = Artisan::call('cv:lint');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('line 42', $output);
        $this->assertStringContainsString('This achievement has no ID.', $output);
        $this->assertStringContainsString('1 problem to fix', $output);
    }

    /**
     * A terminal has no button, so the command is the only way to offer the
     * fix here. The finding itself says nothing about it: see the review UI's
     * master CV page, which has the button and stays quiet.
     */
    public function test_it_offers_the_command_that_writes_the_missing_ids(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        Artisan::call('cv:lint');

        $this->assertStringContainsString(
            'Run "php artisan cv:id" to write the IDs that are missing.',
            Artisan::output(),
        );
    }

    /**
     * Written for the CV's author: no class names, no stack traces, no JSON.
     */
    public function test_its_output_is_written_for_the_cvs_author(): void
    {
        $this->storeMasterCv('master-cv/broken-skill-names-no-role.md');

        Artisan::call('cv:lint');
        $output = Artisan::output();

        $this->assertStringContainsString(
            '"Where used" for "PHP 8.x (8.3)" names no role or project in this file.',
            $output,
        );
        $this->assertStringNotContainsString('App\\', $output);
        $this->assertStringNotContainsString('{"', $output);
    }

    public function test_a_failing_lint_is_logged_as_counts_and_never_as_content(): void
    {
        $this->storeMasterCv('master-cv/broken-duplicate-id.md');

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('master_cv.lint_failed', Mockery::on(function (array $context): bool {
                $this->assertSame(1, $context['problems']);
                $this->assertSame(['ids' => 1], $context['by_check']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->artisan('cv:lint')->assertFailed();
    }

    /**
     * A fresh installation has no CV, which is the ordinary first state. The
     * command says what to do about it rather than reporting a missing file.
     */
    public function test_it_says_so_plainly_when_no_cv_is_stored(): void
    {
        $this->assertSame(Command::FAILURE, Artisan::call('cv:lint'));

        $output = Artisan::output();

        $this->assertStringContainsString('No master CV is stored.', $output);
        $this->assertStringContainsString('cv:import', $output);
    }
}
