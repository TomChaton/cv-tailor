<?php

namespace Tests\Feature\Console;

use App\Observability\EventLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvLintCommandTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_passes_a_cv_that_is_fit_to_generate_from(): void
    {
        $this->artisan('cv:lint', ['path' => $this->fixturePath()])
            ->expectsOutputToContain('Nothing to fix.')
            ->assertSuccessful();
    }

    public function test_it_defaults_to_the_configured_master_cv(): void
    {
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/minimal.md')]);

        $this->artisan('cv:lint')
            ->expectsOutputToContain('minimal.md')
            ->assertSuccessful();
    }

    /**
     * The exit code is what generation and the scheduler rely on, so a failure
     * has to be non-zero as well as legible.
     */
    public function test_it_fails_and_names_the_line_and_the_problem(): void
    {
        $exitCode = Artisan::call('cv:lint', ['path' => $this->fixturePath('master-cv/broken-missing-id.md')]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('line 42', $output);
        $this->assertStringContainsString('This achievement has no ID', $output);
        $this->assertStringContainsString('1 problem to fix', $output);
    }

    /**
     * Written for the CV's author: no class names, no stack traces, no JSON.
     */
    public function test_its_output_is_written_for_the_cvs_author(): void
    {
        Artisan::call('cv:lint', ['path' => $this->fixturePath('master-cv/broken-tech-stack-not-in-index.md')]);
        $output = Artisan::output();

        $this->assertStringContainsString('"Redis" is in this tech stack but not in the skills index', $output);
        $this->assertStringNotContainsString('App\\', $output);
        $this->assertStringNotContainsString('{"', $output);
    }

    public function test_a_failing_lint_is_logged_as_counts_and_never_as_content(): void
    {
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

        $this->artisan('cv:lint', ['path' => $this->fixturePath('master-cv/broken-duplicate-id.md')])
            ->assertFailed();
    }

    public function test_it_says_so_plainly_when_there_is_no_cv_to_read(): void
    {
        $this->artisan('cv:lint', ['path' => '/var/lib/cv-tailor/nothing-here.md'])
            ->expectsOutputToContain('cannot be read')
            ->assertFailed();
    }
}
