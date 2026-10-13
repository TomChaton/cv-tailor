<?php

namespace App\Console\Commands;

use App\Generation\QueuePostings;
use App\MasterCv\MasterCvIsNotUsable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Decides which captured postings are ready to be generated from.
 *
 * Scheduled, because the decision depends on a master CV that parses and
 * lints and a capture does not. Runnable by hand, because the answer to a
 * queue that has stopped is to fix the CV and run this.
 */
class CvQueueCommand extends Command
{
    protected $signature = 'cv:queue';

    protected $description = 'Queue captured postings for generation.';

    public function handle(QueuePostings $queue): int
    {
        try {
            $outcome = $queue->handle();
        } catch (MasterCvIsNotUsable $exception) {
            $this->newLine();
            $this->line('  <fg=red>'.OutputFormatter::escape($exception->getMessage()).'</>');
            $this->line('  <fg=red>Nothing is queued. The postings are waiting, and nothing is lost.</>');
            $this->newLine();

            // Non-zero so that a person running this by hand sees that it
            // failed, rather than reading silence as nothing to do.
            return self::FAILURE;
        }

        if ($outcome->postings === 0) {
            $this->components->info('Nothing waiting.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d %s queued from master CV version %d.',
            $outcome->postings,
            $outcome->postings === 1 ? 'posting' : 'postings',
            $outcome->masterCvVersionId,
        ));

        return self::SUCCESS;
    }
}
