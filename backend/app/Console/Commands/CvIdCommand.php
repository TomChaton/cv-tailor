<?php

namespace App\Console\Commands;

use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\Ids\IdAssignment;
use App\MasterCv\MasterCvIsNotUsable;
use App\MasterCv\ReadMasterCv;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds the IDs the stored master CV is missing and writes the whole document
 * out.
 *
 * Run once on a CV that predates the IDs, and idempotent afterwards. It hands
 * the result back rather than writing it into the stored CV: the author reads
 * a diff and adopts what they meant to, which is the same bargain the review
 * UI's download offers.
 *
 * With no destination the document goes to standard output, so the report goes
 * to standard error and the redirect stays a document.
 */
class CvIdCommand extends Command
{
    protected $signature = 'cv:id
        {destination? : Where to write the result. Standard output when omitted.}
        {--force : Overwrite the destination if it is already there}';

    protected $description = 'Add the IDs the master CV is missing, writing the result somewhere new.';

    public function handle(Filesystem $files, ReadMasterCv $read, AssignMasterCvIds $assign): int
    {
        $destination = $this->stringArgument('destination');

        try {
            [$markdown] = $read->handle();
        } catch (MasterCvIsNotUsable $exception) {
            $this->newLine();
            $this->line('  <fg=red>'.OutputFormatter::escape($exception->getMessage()).'</>');
            $this->newLine();

            return self::FAILURE;
        }

        if ($destination !== null && $files->exists($destination) && $this->option('force') !== true) {
            $this->components->error(sprintf('%s is already there. Pass --force to overwrite it.', $destination));

            return self::FAILURE;
        }

        $assignment = $assign->handle($markdown);

        if ($destination === null) {
            $this->output->write($assignment->content, false, OutputInterface::OUTPUT_RAW);
            $this->toStandardError($this->summary($assignment));

            return self::SUCCESS;
        }

        $files->put($destination, $assignment->content);
        $this->components->info($this->summary($assignment));

        return self::SUCCESS;
    }

    private function summary(IdAssignment $assignment): string
    {
        if ($assignment->total() === 0) {
            return 'Every unit already has an ID.';
        }

        $parts = [];

        foreach ($assignment->added as $kind => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.str_replace('_', '-', $kind);
            }
        }

        return sprintf('Added %d IDs: %s.', $assignment->total(), implode(', ', $parts));
    }

    private function toStandardError(string $message): void
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
        }
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
