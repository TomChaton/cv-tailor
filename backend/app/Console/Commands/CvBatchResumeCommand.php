<?php

namespace App\Console\Commands;

use App\Batching\BatchEvents;
use App\Batching\SubmitterHalt;
use App\Models\Revision;
use Illuminate\Console\Command;

/**
 * Arms the submitter again after it has halted itself.
 *
 * This command is the only thing that clears the halt — not a deploy, not a
 * restart, not a timer. A state that cleared itself would let a restart
 * quietly resume submitting requests the provider refuses.
 */
class CvBatchResumeCommand extends Command
{
    protected $signature = 'cv:batch-resume';

    protected $description = 'Clear the submitter halt.';

    public function handle(SubmitterHalt $halt, BatchEvents $events): int
    {
        if (! $halt->isHalted()) {
            $this->components->info('The submitter is running.');

            return self::SUCCESS;
        }

        // The submitter's own definition of waiting, not a second copy of it:
        // this number is read as what the next run will send, so a revision
        // the submitter declines must not be counted here. One behind a
        // discarded posting is the case, and it would have the owner looking
        // for a fault in the command they were told to run.
        $waiting = Revision::query()->waiting()->count();
        $ids = $halt->resume();

        $events->submitterResumed($ids, $waiting);

        $this->components->info(sprintf(
            'The submitter is running. %d pending %s waiting.',
            $waiting,
            $waiting === 1 ? 'revision is' : 'revisions are',
        ));

        return self::SUCCESS;
    }
}
