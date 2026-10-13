<?php

namespace App\Rendering\Gotenberg;

use App\Rendering\PdfConversionFailed;
use App\Rendering\PdfConverter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Converts HTML to PDF with Gotenberg, over the Compose network.
 *
 * Chromium lives in a service of its own so it stays out of the PHP image, and
 * this is the only part of the application that knows the converter's HTTP
 * shape. The paper size is set here rather than in the document's CSS: a page
 * size is a property of the conversion, and the same HTML has to be able to
 * produce a different one later.
 */
final readonly class GotenbergPdfConverter implements PdfConverter
{
    /**
     * A4, in the inches Gotenberg counts in. Its own default is US Letter.
     */
    private const PAPER = ['paperWidth' => '8.27', 'paperHeight' => '11.69'];

    private const MARGINS = [
        'marginTop' => '0.6',
        'marginBottom' => '0.6',
        'marginLeft' => '0.6',
        'marginRight' => '0.6',
    ];

    public function __construct(
        private Factory $http,
        private string $url,
        private int $timeout = 60,
    ) {}

    public function convert(string $html): string
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->asMultipart()
                // Gotenberg renders the part named index.html and treats the
                // rest of the form as options.
                ->attach('files', $html, 'index.html')
                ->post(rtrim($this->url, '/').'/forms/chromium/convert/html', [
                    ...self::PAPER,
                    ...self::MARGINS,
                    'printBackground' => 'true',
                ]);
        } catch (ConnectionException $exception) {
            throw PdfConversionFailed::unreachable($exception);
        }

        if (! $response->successful()) {
            throw PdfConversionFailed::status($response->status());
        }

        $pdf = $response->body();

        if ($pdf === '') {
            throw PdfConversionFailed::empty();
        }

        return $pdf;
    }
}
