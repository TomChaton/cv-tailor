<?php

use App\Observability\Listeners\RecordSchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

// A scheduled task, not a loop of its own: a heartbeat that could run while
// the scheduler was wedged would report nothing useful.
Schedule::call(RecordSchedulerHeartbeat::class)
    ->everyMinute()
    ->name('scheduler.heartbeat')
    ->withoutOverlapping();
