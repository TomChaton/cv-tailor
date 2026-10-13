<?php

namespace App\MasterCv\Lint;

/**
 * One thing lint has to say about a master CV, addressed to the CV's author.
 *
 * `check` names the check that raised it, which is what the log event counts
 * by. The message is for a person reading their own file: no class names, no
 * stack traces, and nothing a developer would have to translate.
 *
 * It states the problem and never the remedy. A finding is read in a terminal
 * and in the review UI, and what to do about it differs between them, so a
 * problem with a known fix names it in `remedy` and leaves the wording to
 * whichever surface is doing the telling.
 */
final readonly class Finding
{
    public function __construct(
        public string $check,
        public FindingLevel $level,
        public ?int $line,
        public string $message,
        public ?Remedy $remedy = null,
    ) {}

    public static function problem(string $check, ?int $line, string $message, ?Remedy $remedy = null): self
    {
        return new self($check, FindingLevel::Problem, $line, $message, $remedy);
    }

    public static function note(string $check, ?int $line, string $message): self
    {
        return new self($check, FindingLevel::Note, $line, $message);
    }
}
