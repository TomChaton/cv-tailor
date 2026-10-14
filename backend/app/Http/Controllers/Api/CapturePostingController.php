<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CapturePostingRequest;
use App\Postings\CapturePosting;
use Illuminate\Http\JsonResponse;

class CapturePostingController extends Controller
{
    public function __construct(private readonly CapturePosting $capture) {}

    /**
     * Capture a posting.
     *
     * Idempotent: a capture whose page text matches a posting already held
     * answers with that posting, so the extension may retry a send it never saw
     * the answer to. **A posting this capture created answers 201; one that
     * already existed answers 200.**
     *
     * The response carries the posting's identity and nothing else. The
     * extension has just sent the text and does not need it back.
     */
    public function __invoke(CapturePostingRequest $request): JsonResponse
    {
        $captured = $this->capture->handle($request->toCapture());

        $posting = [
            'id' => $captured->id,
            'status' => $captured->status,
        ];

        // A new posting answers 201 and a match answers 200. The extension's
        // two messages differ — saved, against already saved — and a status
        // code is the cheapest place for that distinction to live.
        //
        // Both codes are written out rather than chosen inside one call: the
        // OpenAPI document is generated from this method, and it can only
        // describe a status it can read here.
        return $captured->isNew
            ? response()->json($posting, 201)
            : response()->json($posting, 200);
    }
}
