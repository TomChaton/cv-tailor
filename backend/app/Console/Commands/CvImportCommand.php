<?php

namespace App\Console\Commands;

use App\MasterCv\ImportMasterCv;
use App\MasterCv\MasterCvImportRefused;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Brings a master CV that exists as a file into the application.
 *
 * For somebody who has been keeping their CV in an editor, or a fresh
 * installation starting from the fictional template. It is run once; after it
 * the CV is edited in the review UI.
 */
class CvImportCommand extends Command
{
    protected $signature = 'cv:import
        {path : The Markdown file to import}
        {--force : Replace the master CV that is already stored}';

    protected $description = 'Import a master CV from a file.';

    public function handle(ImportMasterCv $import): int
    {
        $path = $this->argument('path');

        try {
            $report = $import->handle(is_string($path) ? $path : '', $this->option('force') === true);
        } catch (MasterCvImportRefused $refused) {
            $this->newLine();
            $this->line('  <fg=red>'.OutputFormatter::escape($refused->getMessage()).'</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info(sprintf('Imported %s characters.', number_format(mb_strlen($report->markdown))));

        // A CV with problems in it is still imported: it is a draft, and
        // refusing to store it would make its problems unfixable. Saying so
        // is what stops the import reading as a CV that is ready to use.
        if (! $report->passes()) {
            $this->line(sprintf(
                '  <fg=red>%s. Run "php artisan cv:lint" to see them.</>',
                $this->plural(count($report->lint?->problems() ?? []), 'problem'),
            ));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
