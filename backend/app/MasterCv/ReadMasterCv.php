<?php

namespace App\MasterCv;

use App\MasterCv\Lint\LintReport;
use App\MasterCv\Lint\MasterCvLinter;
use App\Models\MasterCv;
use App\Observability\EventLogger;

/**
 * Reads the stored master CV, parses it and lints it.
 *
 * The one place that turns Markdown into a parse and a verdict, so that
 * `cv:lint`, `cv:status`, the review UI and generation all agree on what the
 * CV says and on whether it is fit to be used.
 */
class ReadMasterCv
{
    public function __construct(
        private readonly MasterCvParser $parser,
        private readonly MasterCvLinter $linter,
        private readonly EventLogger $log,
    ) {}

    /**
     * The stored CV, or nothing at all.
     *
     * A fresh installation has none, which is the ordinary first state rather
     * than a fault: the owner has not written one yet.
     */
    public function stored(): ?MasterCv
    {
        return MasterCv::query()->first();
    }

    /**
     * @return array{0: string, 1: ParsedMasterCv, 2: LintReport} The Markdown,
     *                                                            the parse and
     *                                                            the lint.
     *
     * @throws MasterCvIsNotUsable When nothing is stored.
     */
    public function handle(): array
    {
        $markdown = $this->stored()?->markdown;

        if ($markdown === null) {
            throw MasterCvIsNotUsable::notStored();
        }

        return [$markdown, ...$this->parse($markdown)];
    }

    /**
     * Markdown the caller already holds, read as a master CV.
     *
     * What a save has in its hands, and what `cv:preview` has when it is
     * pointed at a fixture.
     *
     * @return array{0: ParsedMasterCv, 1: LintReport}
     */
    public function parse(string $markdown): array
    {
        $parsed = $this->parser->parse($markdown);

        return [$parsed, $this->linter->lint($parsed)];
    }

    /**
     * A failed lint is a warning rather than an error: the CV is the author's
     * to fix, and the process that found it carries on.
     *
     * The counts go to the log and the findings do not. A finding quotes the
     * CV, and a log line that would be embarrassing in a public bug report is
     * a bug.
     */
    public function logFailure(LintReport $report): void
    {
        $this->log->warning('master_cv.lint_failed', [
            'problems' => count($report->problems()),
            'by_check' => $report->problemsByCheck(),
        ]);
    }
}
