<?php

namespace App\Rendering;

/**
 * Turns one resolved document into the Word file the owner edits.
 *
 * Behind an interface for the same reason the PDF converter is: the one
 * failure path that matters here — the Word file not being written while the
 * PDF was — has to be reachable from a test without a service to break.
 */
interface DocxWriter
{
    /**
     * @return string The `.docx` file's bytes.
     */
    public function write(ResolvedDocument $document): string;
}
