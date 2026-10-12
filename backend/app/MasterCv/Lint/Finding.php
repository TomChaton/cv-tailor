<?php

namespace App\MasterCv\Lint;

/**
 * One thing lint has to say about a master CV, addressed to the CV's author.
 *
 * `check` names the check that raised it, which is what the log event counts
 * by. The message is for a person reading their own file: no class names, no
 * stack traces, and nothing a developer would have to translate.
 */
final readonly class Finding
{
    public function __construct(
        public string $check,
        public FindingLevel $level,
        public ?int $line,
        public string $message,
    ) {}

    public static function problem(string $check, ?int $line, string $message): self
    {
        return new self($check, FindingLevel::Problem, $line, $message);
    }

    public static function note(string $check, ?int $line, string $message): self
    {
        return new self($check, FindingLevel::Note, $line, $message);
    }
}
