<?php

namespace App\MasterCv;

use App\MasterCv\Lint\LintReport;
use App\MasterCv\Lint\MasterCvLinter;
use App\Observability\EventLogger;
use Illuminate\Filesystem\Filesystem;

/**
 * Reads a master CV from disk, parses it and lints it.
 *
 * The one place that turns a path into a parse, so that `cv:lint`, `cv:status`
 * and generation all agree on what the file says and on whether it is fit to
 * be used.
 */
class ReadMasterCv
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly MasterCvParser $parser,
        private readonly MasterCvLinter $linter,
        private readonly EventLogger $log,
        private readonly string $path,
    ) {}

    public function path(?string $path = null): string
    {
        return $path ?? $this->path;
    }

    /**
     * @return array{0: string, 1: ParsedMasterCv, 2: LintReport} The raw file,
     *                                                            the parse and
     *                                                            the lint.
     */
    public function handle(?string $path = null): array
    {
        $path = $this->path($path);

        if (! $this->files->isFile($path) || ! $this->files->isReadable($path)) {
            throw MasterCvIsNotUsable::unreadable($path);
        }

        $raw = $this->files->get($path);
        $parsed = $this->parser->parse($raw);

        return [$raw, $parsed, $this->linter->lint($parsed)];
    }

    /**
     * A failed lint is a warning rather than an error: the file is the
     * author's to fix, and the process that found it carries on.
     *
     * The counts go to the log and the findings do not. A finding quotes the
     * CV, and a log line that would be embarrassing in a public bug report is
     * a bug.
     */
    public function logFailure(string $path, LintReport $report): void
    {
        $this->log->warning('master_cv.lint_failed', [
            'path' => $path,
            'problems' => count($report->problems()),
            'by_check' => $report->problemsByCheck(),
        ]);
    }
}
