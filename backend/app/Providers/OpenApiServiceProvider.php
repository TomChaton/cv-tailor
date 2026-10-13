<?php

namespace App\Providers;

use App\OpenApi\BodyLimitResponse;
use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Support\ServiceProvider;

/**
 * The OpenAPI document is the contract the extension and backend meet at, and
 * it is generated from the routes, the form requests and the controllers. This
 * provider settles the two things the generator cannot read off the code.
 */
class OpenApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scramble serves the document and a browsable rendering of it by
        // default. The committed file is the artifact this project uses, and
        // the review UI is the only thing the application serves to a browser,
        // so neither route is wanted. Read in the generator's boot, hence
        // `register`.
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        Scramble::configure()
            ->withOperationTransformers(fn (OperationTransformers $transformers) => $transformers->append(BodyLimitResponse::class));

        // Every instance is self-hosted at an address of its own, so the
        // document names no server: the extension's client is handed a base
        // URL, and one document then describes every installation. Left to
        // itself the generator would write whatever `APP_URL` happened to say
        // when the file was exported.
        Scramble::afterOpenApiGenerated(function (OpenApi $document): void {
            $document->servers = [];
        });
    }
}
