<?php

namespace App\Console\Commands;

use App\Batching\PollBatches;
use Illuminate\Console\Command;

/**
 * Asks the provider about every batch that still owes us results, and takes
 * the results of the ones that have finished.
 *
 * Scheduled. Runnable by hand, because a batch that has not been collected is
 * work that has been paid for and will expire.
 */
class CvBatchPollCommand extends Command
{
    protected $signature = 'cv:batch-poll';

    protected $description = 'Collect the results of every batch the provider has finished.';

    public function handle(PollBatches $poll): int
    {
        $outcome = $poll->handle();

        if ($outcome->open === 0) {
            $this->components->info('No open batches.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d open: %d ended, %d collected.',
            $outcome->open,
            $outcome->ended,
            $outcome->drained,
        ));

        if ($outcome->stale > 0) {
            $this->components->warn(sprintf(
                '%d %s been open too long.',
                $outcome->stale,
                $outcome->stale === 1 ? 'batch has' : 'batches have',
            ));
        }

        if ($outcome->unreachable > 0) {
            $this->components->warn(sprintf(
                'The provider would not answer about %d %s.',
                $outcome->unreachable,
                $outcome->unreachable === 1 ? 'batch' : 'batches',
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
