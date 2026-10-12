<?php

namespace App\MasterCv;

/**
 * One line of the master CV, with the number it has in the file.
 *
 * Line numbers travel with everything the parser produces: lint output has to
 * tell the author where to look, and the only address a Markdown document has
 * is a line.
 */
final readonly class Line
{
    public function __construct(
        public int $number,
        public string $text,
    ) {}
}
