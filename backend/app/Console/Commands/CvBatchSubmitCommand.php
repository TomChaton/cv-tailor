<?php

namespace App\Console\Commands;

use App\Batching\SubmitBatch;
use App\Llm\LlmFailure;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Sends everything that is waiting to the provider.
 *
 * Scheduled, because nobody is sitting watching for a revision to become a
 * CV. Runnable by hand, because the answer to a queue that has stopped is to
 * fix what stopped it and run this.
 */
class CvBatchSubmitCommand extends Command
{
    protected $signature = 'cv:batch-submit';

    protected $description = 'Submit every pending revision to the provider as one batch.';

    public function handle(SubmitBatch $submit): int
    {
        try {
            $outcome = $submit->handle();
        } catch (LlmFailure $failure) {
            $this->newLine();
            $this->line('  <fg=red>'.OutputFormatter::escape($failure->getMessage()).'</>');
            $this->line('  <fg=red>Nothing was submitted. Every revision is still pending.</>');
            $this->newLine();

            return self::FAILURE;
        }

        if ($outcome->halted) {
            $this->newLine();
            $this->line('  <fg=red>The submitter is halted: '
                .'two consecutive batches were refused as malformed requests ('
                .implode(', ', array_map(static fn (int $id): string => 'batch '.$id, $outcome->haltedBy))
                .').</>');
            $this->line('  <fg=red>Nothing is submitted until php artisan cv:batch-resume.</>');
            $this->newLine();

            return self::FAILURE;
        }

        if ($outcome->requestCount === 0 && $outcome->refused === 0) {
            $this->components->info('Nothing pending.');

            return self::SUCCESS;
        }

        if ($outcome->requestCount === 0) {
            $this->newLine();
            $this->line(sprintf(
                '  <fg=red>Nothing was submitted: %d pending %s cannot be built. '
                .'Run php artisan cv:lint.</>',
                $outcome->refused,
                $outcome->refused === 1 ? 'revision' : 'revisions',
            ));
            $this->newLine();

            // Non-zero, so that someone running this by hand after fixing
            // their CV sees that it is still not fixed.
            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d %s submitted as batch %d (%s).',
            $outcome->requestCount,
            $outcome->requestCount === 1 ? 'revision' : 'revisions',
            $outcome->llmBatchId,
            $outcome->providerBatchId,
        ));

        if ($outcome->refused > 0) {
            $this->components->warn(sprintf(
                '%d pending %s could not be built. Run php artisan cv:lint.',
                $outcome->refused,
                $outcome->refused === 1 ? 'revision' : 'revisions',
            ));
        }

        return self::SUCCESS;
    }
}
