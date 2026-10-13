<?php

namespace App\Http;

use App\Observability\EventLogger;

/**
 * The API's own log lines — the ones about a request rather than about what it
 * was asking for.
 *
 * Event names are part of the interface, so they live here rather than at their
 * call sites and are not renamed casually.
 */
final readonly class HttpEvents
{
    public function __construct(private EventLogger $log) {}

    /**
     * A warning: a refused body is a capture the owner thinks they have, and
     * the sizes are what says whether the limit is wrong or the page was.
     */
    public function requestTooLarge(string $path, int $bytes, int $limitBytes): void
    {
        $this->log->warning('request.too_large', [
            'path' => $path,
            'bytes' => $bytes,
            'limit_bytes' => $limitBytes,
        ]);
    }
}
