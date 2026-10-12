<?php

namespace Tests\Feature\Console;

use App\Observability\HeartbeatStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CvStatusCommandTest extends TestCase
{
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
            ->expectsOutputToContain('yes')
            ->assertSuccessful();

        unlink($path);
    }

    public function test_it_reports_a_master_cv_it_cannot_read(): void
    {
        config(['cv.master_cv.path' => '/var/lib/cv-tailor/nothing-here.md']);

        $this->artisan('cv:status')
            ->expectsOutputToContain('nothing-here.md')
            ->expectsOutputToContain('unknown')
            ->assertSuccessful();
    }
}
