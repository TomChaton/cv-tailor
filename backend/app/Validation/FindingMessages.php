<?php

namespace App\Validation;

use Illuminate\Contracts\Translation\Translator;

/**
 * The words a finding is written in.
 *
 * A finding is read by the owner in the review UI, so its text is a catalogue
 * entry like any other word on a screen and never a literal in a check. The
 * rendered sentence is what is stored on the revision: one locale, one owner,
 * and a message stored as written is a message that still reads the same after
 * the check that raised it has been rewritten.
 */
final readonly class FindingMessages
{
    public function __construct(private Translator $translator) {}

    /**
     * @param  array<string, string>  $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $message = $this->translator->get('findings.'.$key, $replace);

        return is_string($message) ? $message : $key;
    }
}
