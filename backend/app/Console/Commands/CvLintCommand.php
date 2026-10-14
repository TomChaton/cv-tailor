<?php

namespace App\Console\Commands;

use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\Remedy;
use App\MasterCv\MasterCvIsNotUsable;
use App\MasterCv\ReadMasterCv;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Tells the CV's author whether the stored CV is fit to generate from.
 *
 * The output is written for them and not for a developer: no class names, no
 * stack traces, no JSON, and a line number for everything. The exit code is
 * non-zero on failure so that generation and the scheduler can both rely on
 * it.
 */
class CvLintCommand extends Command
{
    protected $signature = 'cv:lint';

    protected $description = 'Check the master CV before generating from it.';

    public function handle(ReadMasterCv $read): int
    {
        try {
            [, , $report] = $read->handle();
        } catch (MasterCvIsNotUsable $exception) {
            $this->newLine();
            $this->line('  <fg=red>'.OutputFormatter::escape($exception->getMessage()).'</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($report->problems() as $problem) {
            $this->finding($problem, 'red');
        }

        foreach ($report->notes() as $note) {
            $this->finding($note, 'yellow');
        }

        $this->newLine();

        if ($report->passes()) {
            $this->line('  <fg=green>Nothing to fix.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $read->logFailure($report);

        $this->line(sprintf(
            '  <fg=red>%s to fix before this CV can be generated from.</>',
            $this->plural(count($report->problems()), 'problem'),
        ));

        // A finding names what is wrong and leaves what to do about it to
        // whoever is reading. In a terminal that is a command; the review UI
        // has a button for the same thing and says nothing.
        foreach ($report->remedies() as $remedy) {
            $this->newLine();
            $this->line('  '.OutputFormatter::escape($this->remedy($remedy)));
        }

        $this->newLine();

        return self::FAILURE;
    }

    private function remedy(Remedy $remedy): string
    {
        return match ($remedy) {
            Remedy::AddMissingIds => 'Run "php artisan cv:id" to write the IDs that are missing.',
        };
    }

    private function finding(Finding $finding, string $colour): void
    {
        $where = $finding->line === null ? '' : 'line '.$finding->line;

        $this->line(sprintf(
            '  <fg=%s>%-9s</> %s',
            $colour,
            $where,
            OutputFormatter::escape($finding->message),
        ));
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
