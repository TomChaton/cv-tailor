<?php

namespace Tests\Feature\Console;

use App\Models\Posting;
use App\Postings\PostingStatus;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvQueueCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_reports_what_it_queued(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        Posting::factory()->wanted()->count(2)->create();

        $this->artisan('cv:queue')
            ->expectsOutputToContain('2 postings queued from master CV version 1.')
            ->assertSuccessful();

        $this->assertDatabaseCount('revisions', 2);
    }

    public function test_it_reports_a_single_posting_as_one(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        Posting::factory()->wanted()->create();

        $this->artisan('cv:queue')
            ->expectsOutputToContain('1 posting queued from master CV version 1.')
            ->assertSuccessful();
    }

    public function test_it_succeeds_with_nothing_waiting(): void
    {
        $this->artisan('cv:queue')
            ->expectsOutputToContain('Nothing waiting.')
            ->assertSuccessful();
    }

    /**
     * The gate, from the command somebody runs by hand: a captured posting is
     * no work at all, and only the owner can make it some.
     */
    public function test_a_captured_posting_is_not_work(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        Posting::factory()->create();

        $this->artisan('cv:queue')
            ->expectsOutputToContain('Nothing waiting.')
            ->assertSuccessful();

        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Captured, Posting::query()->sole()->status);
    }

    /**
     * Non-zero, so that someone running this by hand after fixing their CV
     * sees that it is still not fixed.
     */
    public function test_it_fails_when_the_master_cv_cannot_be_used(): void
    {
        $this->storeMasterCv('master-cv/broken-duplicate-id.md');

        Posting::factory()->wanted()->create();

        $this->artisan('cv:queue')
            ->expectsOutputToContain('cv:lint')
            ->expectsOutputToContain('nothing is lost')
            ->assertFailed();

        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Wanted, Posting::query()->sole()->status);
    }

    /**
     * In this process rather than in a subprocess, because the scheduler sends
     * a scheduled command's output to /dev/null and this application's log is
     * its processes' standard error.
     */
    public function test_it_is_scheduled_every_five_minutes_in_process_and_never_overlaps(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'cv:queue',
        ));

        $this->assertCount(1, $events);
        $this->assertInstanceOf(CallbackEvent::class, $events[0]);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_the_scheduled_run_queues_what_is_waiting(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        Posting::factory()->wanted()->create();

        $this->scheduled()->run(app());

        $this->assertDatabaseCount('revisions', 1);
        $this->assertSame(PostingStatus::Queued, Posting::query()->sole()->status);
    }

    /**
     * A sweep leaves a captured posting alone. Nothing on a schedule spends
     * money on a posting the owner has not asked for.
     */
    public function test_the_scheduled_run_leaves_a_captured_posting_alone(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        Posting::factory()->create();

        $this->scheduled()->run(app());

        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Captured, Posting::query()->sole()->status);
    }

    /**
     * The alarm is the `queue.blocked` warning the run writes, not a failed
     * task: a reported failure here names no command and carries a stack trace
     * that is all framework.
     */
    public function test_the_scheduled_run_leaves_everything_waiting_when_the_master_cv_cannot_be_used(): void
    {
        $this->storeMasterCv('master-cv/broken-duplicate-id.md');

        Posting::factory()->wanted()->create();

        $event = $this->scheduled();
        $event->run(app());

        $this->assertSame(0, $event->exitCode);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Wanted, Posting::query()->sole()->status);
    }

    private function scheduled(): CallbackEvent
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => $event->description === 'cv:queue',
        ));

        $this->assertInstanceOf(CallbackEvent::class, $events[0]);

        return $events[0];
    }
}
