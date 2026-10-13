<?php

namespace App\Rendering;

/**
 * Turns the rendered HTML of one document into a PDF.
 *
 * Behind an interface because the converter is a separate service with a
 * browser in it, and because no test may start one: the test environment binds
 * the fake, so reaching a live converter from a test takes deliberately undoing
 * that rather than forgetting to fake it.
 */
interface PdfConverter
{
    /**
     * @return string The PDF's bytes.
     *
     * @throws PdfConversionFailed
     */
    public function convert(string $html): string;
}
