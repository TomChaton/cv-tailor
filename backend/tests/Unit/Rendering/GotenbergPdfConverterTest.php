<?php

namespace Tests\Unit\Rendering;

use App\Rendering\Gotenberg\GotenbergPdfConverter;
use App\Rendering\PdfConversionFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

class GotenbergPdfConverterTest extends TestCase
{
    public function test_it_posts_the_html_as_index_html_and_returns_the_pdf(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response('%PDF-1.7 bytes')]);

        $pdf = $this->converter($http)->convert('<p>Hello</p>');

        $this->assertSame('%PDF-1.7 bytes', $pdf);

        $http->assertSent(function (Request $request): bool {
            $this->assertSame('http://gotenberg:3000/forms/chromium/convert/html', $request->url());

            $body = $request->body();

            // Gotenberg renders the part named index.html and reads the rest of
            // the form as options. A4 rather than its own default of Letter.
            $this->assertStringContainsString('filename="index.html"', $body);
            $this->assertStringContainsString('<p>Hello</p>', $body);
            $this->assertStringContainsString('8.27', $body);
            $this->assertStringContainsString('11.69', $body);

            return true;
        });
    }

    public function test_a_failure_status_is_reported_without_the_response_body(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response('the document said something quotable', 502)]);

        try {
            $this->converter($http)->convert('<p>Hello</p>');
            $this->fail('A failure status must not pass as a PDF.');
        } catch (PdfConversionFailed $failure) {
            $this->assertSame('it answered 502', $failure->reason);
            $this->assertStringNotContainsString('quotable', $failure->getMessage());
        }
    }

    public function test_an_empty_body_is_not_a_pdf(): void
    {
        $http = new Factory;
        $http->fake(['*' => Factory::response('')]);

        $this->expectException(PdfConversionFailed::class);

        $this->converter($http)->convert('<p>Hello</p>');
    }

    public function test_a_converter_that_is_not_there_is_reported_as_unreachable(): void
    {
        $http = new Factory;
        $http->fake(['*' => fn (): never => throw new ConnectionException('no route to host')]);

        try {
            $this->converter($http)->convert('<p>Hello</p>');
            $this->fail('An unreachable converter must not pass as a PDF.');
        } catch (PdfConversionFailed $failure) {
            $this->assertSame('it could not be reached', $failure->reason);
        }
    }

    private function converter(Factory $http): GotenbergPdfConverter
    {
        // A trailing slash on the configured URL is the easiest thing to get
        // wrong in an env file, and the hardest to see in a 404.
        return new GotenbergPdfConverter($http, 'http://gotenberg:3000/');
    }
}
