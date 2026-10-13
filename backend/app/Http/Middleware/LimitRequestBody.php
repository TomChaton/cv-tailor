<?php

namespace App\Http\Middleware;

use App\Http\HttpEvents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refuses a request body larger than the configured limit.
 *
 * A capture carries a page's whole HTML, so without a stated ceiling the
 * largest page anyone browses decides how much memory this application uses.
 * The limit is checked against the declared length before anything reads the
 * body, so an oversized capture costs nothing to refuse.
 *
 * A body with no declared length is left to PHP's own `post_max_size`, which
 * is the only ceiling available before the bytes are read.
 */
class LimitRequestBody
{
    public function __construct(
        private readonly HttpEvents $events,
        private readonly int $maxBytes,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if ($declared !== null && (int) $declared > $this->maxBytes) {
            $this->events->requestTooLarge($request->path(), (int) $declared, $this->maxBytes);

            // The message reaches the extension, not a person: the sentence
            // the owner reads comes from the extension's own catalogue.
            throw new HttpException(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                'The request body is larger than this instance accepts.',
            );
        }

        return $next($request);
    }
}
