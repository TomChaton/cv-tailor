<?php

namespace App\Rendering\Word;

/**
 * One stretch of text in a paragraph, with the emphasis that applies to it.
 *
 * A Word paragraph is a list of runs rather than a string with tags in it, so
 * the inline markup a master CV's bullets carry has to arrive here as a shape
 * the template can iterate.
 */
final readonly class WordRun
{
    public function __construct(
        public string $text,
        public bool $bold = false,
        public bool $italic = false,
        public bool $code = false,
    ) {}

    public function emphasised(): bool
    {
        return $this->bold || $this->italic || $this->code;
    }
}
