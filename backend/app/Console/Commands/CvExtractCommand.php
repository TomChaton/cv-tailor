<?php

namespace App\Console\Commands;

use App\Extraction\ExtractWaitingPostings;
use Illuminate\Console\Command;

/**
 * Asks again about every posting extraction has not answered.
 *
 * Run by hand rather than scheduled: extraction is retried by the queue on its
 * own terms, and once those attempts are spent what is left to change is
 * configuration. This is how a configuration that has been fixed reaches the
 * postings that were waiting on it, without any of them being captured again.
 */
class CvExtractCommand extends Command
{
    protected $signature = 'cv:extract';

    protected $description = 'Ask again about every posting extraction has not answered.';

    public function handle(ExtractWaitingPostings $extract): int
    {
        $postings = $extract->handle();

        if ($postings === 0) {
            $this->components->info('Nothing waiting.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d %s queued for extraction.',
            $postings,
            $postings === 1 ? 'posting' : 'postings',
        ));

        return self::SUCCESS;
    }
}
