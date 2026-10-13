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

// Nobody is waiting on a capture to become a revision, so five minutes is
// soon enough, and a run that finds nothing costs one query. The task cannot
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
