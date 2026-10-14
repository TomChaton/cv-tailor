<?php

namespace App\Console\Commands;

use App\MasterCv\MasterCvIsNotUsable;
use App\MasterCv\ReadMasterCv;
use App\Rendering\Html\CvHtml;
use App\Rendering\PdfConversionFailed;
use App\Rendering\PdfConverter;
use App\Rendering\ResolveDocument;
use App\Rendering\VerbatimSelection;
use App\Validation\Validator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Renders the whole master CV through the CV template, so the template can be
 * looked at.
 *
 * A diff cannot show a CV that renders badly, clips a column or breaks a page
 * in the wrong place, and neither can a test: the only way to know is to read
 * one. This produces the longest document the template will ever be asked for
 * — every role, every bullet, every skill — from the stored CV, with no model
 * involved and nothing written to the database.
 *
 * `--cv` is the one path this command will read, and it is here for the
 * fictional fixture: seeing what the template does to a CV that exercises
 * every feature of the format must not mean replacing the CV somebody is
 * editing.
 *
 * **It is not a way round the render guard.** The guard stands between a
 * *revision* and a PDF, because a revision carries model output. This carries
 * none: every word of it is the author's own. It is linted and run through the
 * same validators all the same, and refuses on a blocking finding exactly as
 * the guard would, so the one way to a PDF of someone else's words stays the
 * one that is checked.
 */
class CvPreviewCommand extends Command
{
    protected $signature = 'cv:preview
        {destination=/tmp/cv-preview.pdf : Where to write the PDF}
        {--cv= : A Markdown file to render instead of the stored master CV}';

    protected $description = 'Render the whole master CV through the CV template, as a PDF.';

    public function handle(
        ReadMasterCv $read,
        Validator $validator,
        ResolveDocument $resolve,
        CvHtml $html,
        PdfConverter $converter,
        Filesystem $files,
    ): int {
        $destination = $this->stringArgument('destination') ?? '/tmp/cv-preview.pdf';
        $path = $this->stringOption('cv');

        try {
            if ($path === null) {
                [, $cv, $lint] = $read->handle();
            } else {
                if (! $files->isFile($path) || ! $files->isReadable($path)) {
                    return $this->refuse(sprintf('%s cannot be read.', $path));
                }

                [$cv, $lint] = $read->parse($files->get($path));
            }
        } catch (MasterCvIsNotUsable $exception) {
            return $this->refuse($exception->getMessage());
        }

        if (! $lint->passes()) {
            return $this->refuse('The master CV does not pass cv:lint. Run it and fix what it reports.');
        }

        $selection = VerbatimSelection::of($cv);
        $report = $validator->validate($cv, $selection);

        if (! $report->passes()) {
            return $this->refuse(sprintf(
                'The CV as written does not pass its own checks (%s). Nothing is rendered.',
                implode(', ', $report->blockingChecks()),
            ));
        }

        $document = $resolve->resolve($cv, $selection);

        try {
            $pdf = $converter->convert($html->for($document));
        } catch (PdfConversionFailed $failure) {
            return $this->refuse($failure->getMessage());
        }

        $files->put($destination, $pdf);

        $counts = $document->counts();

        $this->components->info(sprintf(
            '%s — %d roles, %d items, %d skills, %s.',
            $destination,
            $counts['roles_included'],
            $counts['items'],
            count($document->skills),
            $this->size(strlen($pdf)),
        ));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->newLine();
        $this->line('  <fg=red>'.OutputFormatter::escape($message).'</>');
        $this->newLine();

        return self::FAILURE;
    }

    private function size(int $bytes): string
    {
        return round($bytes / 1024).' KiB';
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
