<?php

namespace App\Providers;

use App\Rendering\DocxStore;
use App\Rendering\DocxWriter;
use App\Rendering\Gotenberg\GotenbergPdfConverter;
use App\Rendering\PdfConverter;
use App\Rendering\PdfStore;
use App\Rendering\Testing\FakePdfConverter;
use App\Rendering\Word\CvDocx;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires rendering: where a revision's files are written, and what makes them.
 *
 * The converter is bound to the fake in the test environment, so starting a
 * browser from a test takes deliberately undoing this. The real one refuses to
 * be built there at all, which catches a test that resolves the implementation
 * directly rather than the interface.
 *
 * The Word writer has no fake. It is this application's own templates and a
 * zip file, with no service behind it, so a test asserting on what the owner
 * will open has to read a file the real writer produced.
 */
class RenderingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Contextual binding: the stores want one disk, and the alternative is
        // a `Storage::disk()` call buried in domain code.
        $this->app->when([PdfStore::class, DocxStore::class])
            ->needs(Filesystem::class)
            ->give(fn (): Filesystem => Storage::disk('pdfs'));

        $this->app->singleton(DocxWriter::class, CvDocx::class);

        $this->app->singleton(GotenbergPdfConverter::class, function (Application $app): GotenbergPdfConverter {
            if ($app->environment('testing')) {
                throw new RuntimeException(
                    'A test asked for a live PDF converter. Depend on App\Rendering\PdfConverter, which is '
                    .'bound to the fake in the test environment.'
                );
            }

            return new GotenbergPdfConverter($app->make(Factory::class), Config::string('cv.gotenberg.url'));
        });

        $this->app->singleton(FakePdfConverter::class);

        $this->app->singleton(
            PdfConverter::class,
            fn (Application $app): PdfConverter => $app->environment('testing')
                ? $app->make(FakePdfConverter::class)
                : $app->make(GotenbergPdfConverter::class),
        );
    }
}
