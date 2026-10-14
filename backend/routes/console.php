<?php

use App\Observability\Listeners\RecordSchedulerHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// A scheduled task, not a loop of its own: a heartbeat that could run while
// the scheduler was wedged would report nothing useful.
Schedule::call(RecordSchedulerHeartbeat::class)
    ->everyMinute()
    ->name('scheduler.heartbeat')
    ->withoutOverlapping();

// Nobody is waiting on a wanted posting to become a revision, so five minutes
// is soon enough, and a run that finds nothing costs one query. The task cannot
// overlap itself because a run that outlived the interval would otherwise race
// the next one for the same postings.
//
// `Schedule::command()` is the obvious way to run a command on a schedule and
// the wrong one here: it runs the command in a subprocess whose output the
// scheduler sends to /dev/null, and this application's log is its processes'
// standard error. Every event the command emitted would be discarded, and
// `docker compose logs scheduler` is where somebody looks for them. So it runs
// in this process, as the heartbeat does.
//
// The exit code is deliberately not turned into a failed task. A blocked queue
// alarms by writing `queue.blocked`; reporting a failure as well buys an
// error whose message names no command and whose stack trace is all framework,
// which says nothing about the master CV somebody has to go and fix.
Schedule::call(function (): void {
    Artisan::call('cv:queue');
})
    ->everyFiveMinutes()
    ->name('cv:queue')
    ->withoutOverlapping();

// Submitting and polling each run every five minutes, for the reason queueing
// does: nothing in this system is interactive, and a batch takes as long as it
// takes. Both run in this process rather than through `Schedule::command()`,
// so that the events they write reach `docker compose logs scheduler` instead
// of the subprocess's /dev/null.
//
// There is no quorum and no maximum wait. The batch API's discount applies to
// a batch of one, so holding a revision back to wait for company would buy
// nothing and cost it the wait.
Schedule::call(function (): void {
    Artisan::call('cv:batch-submit');
})
    ->everyFiveMinutes()
    ->name('cv:batch-submit')
    ->withoutOverlapping();

// The poller is scheduled separately from the submitter and is never halted by
// it: batches already in flight still need collecting, and a batch that is
// paid for and never collected is the expensive failure here.
Schedule::call(function (): void {
    Artisan::call('cv:batch-poll');
})
    ->everyFiveMinutes()
    ->name('cv:batch-poll')
    ->withoutOverlapping();
