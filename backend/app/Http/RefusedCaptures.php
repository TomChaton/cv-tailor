<?php

namespace App\Http;

use App\Http\Controllers\Api\CapturePostingController;
use App\Postings\PostingEvents;
use Illuminate\Http\Request;

/**
 * Names a capture the API refused before anything read its body.
 *
 * Authentication runs ahead of the controller and the form request, so a
 * capture sent with a token this instance does not hold leaves no trace of its
 * own: the extension is told, and the owner is looking at the extension, but
 * the side that refused says nothing. The line is what tells a token that was
 * never pasted in from a backend that is not answering.
 */
final readonly class RefusedCaptures
{
    public function __construct(private PostingEvents $events) {}

    public function unauthenticated(Request $request): void
    {
        // Authentication failures are raised by every signed-out visit to the
        // review UI as well, and those are not captures.
        if ($request->route()?->getName() !== CapturePostingController::ROUTE) {
            return;
        }

        $this->events->refused('authentication');
    }
}
