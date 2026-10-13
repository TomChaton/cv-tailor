<?php

namespace App\OpenApi;

use App\Http\Middleware\LimitRequestBody;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Documents the refusal an endpoint behind the request body limit answers with.
 *
 * The generator reads the other refusals off the code — 401 from the route's
 * `auth:sanctum`, 422 from the form request's rules — but it cannot see inside
 * a middleware. This reads the route's middleware list, so a 413 appears in the
 * document because the limit is applied rather than because somebody
 * remembered to say so.
 */
class BodyLimitResponse implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! in_array(LimitRequestBody::class, $routeInfo->route->gatherMiddleware(), true)) {
            return;
        }

        // Built a statement at a time: the generator's own setters return no
        // declared type, and a chained call would read as `mixed` to static
        // analysis.
        $message = new OpenApiTypes\StringType;
        $message->setDescription('Error overview.');

        $body = new OpenApiTypes\ObjectType;
        $body->addProperty('message', $message);
        $body->setRequired(['message']);

        /** @var Schema $schema */
        $schema = Schema::fromType($body);

        $response = new Response(413);
        $response->setDescription('Request body larger than the limit this instance accepts');
        $response->setContent('application/json', $schema);

        $operation->addResponse($response);
    }
}
