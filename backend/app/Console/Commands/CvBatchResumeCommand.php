<?php

namespace App\Console\Commands;

use App\Batching\BatchEvents;
use App\Batching\SubmitterHalt;
use App\Models\Revision;
use App\Revisions\RevisionStatus;
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

        $waiting = Revision::query()->where('status', RevisionStatus::Pending)->count();
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
