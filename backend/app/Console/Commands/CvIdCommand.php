<?php

namespace App\Console\Commands;

use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\Ids\IdAssignment;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds the IDs a master CV is missing and writes the whole document out.
 *
 * Run once on a CV that predates the IDs, and idempotent afterwards. It never
 * writes in place: the author reviews a diff and adopts the result, and the
 * master CV's mount stays read-only.
 *
 * With no destination the document goes to standard output, so the report goes
 * to standard error and the redirect stays a document.
 */
class CvIdCommand extends Command
{
    protected $signature = 'cv:id
        {source : The master CV to read}
        {destination? : Where to write the result. Standard output when omitted.}
        {--force : Overwrite the destination if it is already there}';

    protected $description = 'Add the IDs a master CV is missing, writing the result somewhere new.';

    public function handle(Filesystem $files, AssignMasterCvIds $assign): int
    {
        $source = $this->stringArgument('source') ?? '';
        $destination = $this->stringArgument('destination');

        if (! $files->isFile($source) || ! $files->isReadable($source)) {
            $this->components->error(sprintf('%s cannot be read.', $source));

            return self::FAILURE;
        }

        if ($destination !== null && ! $this->destinationIsUsable($files, $source, $destination)) {
            return self::FAILURE;
        }

        $assignment = $assign->handle($files->get($source));

        if ($destination === null) {
            $this->output->write($assignment->content, false, OutputInterface::OUTPUT_RAW);
            $this->toStandardError($this->summary($assignment));

            return self::SUCCESS;
        }

        $files->put($destination, $assignment->content);
        $this->components->info($this->summary($assignment));

        return self::SUCCESS;
    }

    private function destinationIsUsable(Filesystem $files, string $source, string $destination): bool
    {
        // The master CV is mounted read-only on purpose. Refusing the source
        // as a destination keeps that decision from being undone by a typo.
        if ($this->samePath($source, $destination)) {
            $this->components->error('Writing back over the source is not allowed. Give a different path.');

            return false;
        }

        if ($files->exists($destination) && $this->option('force') !== true) {
            $this->components->error(sprintf('%s is already there. Pass --force to overwrite it.', $destination));

            return false;
        }

        return true;
    }

    private function samePath(string $source, string $destination): bool
    {
        $sourcePath = realpath($source);
        $destinationPath = realpath($destination);

        return $sourcePath !== false && $sourcePath === $destinationPath;
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
