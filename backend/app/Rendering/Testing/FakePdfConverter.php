<?php

namespace App\Rendering\Testing;

use App\Rendering\PdfConversionFailed;
use App\Rendering\PdfConverter;

/**
 * Stands in for Gotenberg so that no test starts a browser.
 *
 * It keeps the HTML it was handed, which is how a test asserts on what the
 * template produced without parsing a PDF, and it can be told to fail so the
 * failure path is tested without a service to break.
 */
final class FakePdfConverter implements PdfConverter
{
    /** @var list<string> */
    public array $converted = [];

    private ?PdfConversionFailed $failure = null;

    private int $pages = 1;

    public function convert(string $html): string
    {
        $this->converted[] = $html;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->pdf();
    }

    public function fail(PdfConversionFailed $failure): void
    {
        $this->failure = $failure;
    }

    public function withPages(int $pages): void
    {
        $this->pages = $pages;
    }

    public function lastHtml(): string
    {
        return $this->converted === [] ? '' : $this->converted[count($this->converted) - 1];
    }

    /**
     * Enough of a PDF to be counted: a page object per page, which is what the
     * page count reads.
     */
    private function pdf(): string
    {
        $pages = '';

        for ($page = 1; $page <= $this->pages; $page++) {
            $pages .= $page." 0 obj\n<< /Type /Page >>\nendobj\n";
        }

        return "%PDF-1.7\n".$pages."%%EOF\n";
    }
}
