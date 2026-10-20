<?php

namespace App\Http\Controllers\Review;

use App\Models\Posting;
use App\Providers\Filament\ReviewPanelProvider;
use App\Rendering\CvFilename;
use App\Rendering\PdfStore;
use Illuminate\Http\Response;

/**
 * A posting's current CV, served inline so the review screen can embed it.
 *
 * **The PDF is the owner's.** The route is registered inside the panel, so it
 * carries the panel's session authentication and nothing else reaches it: not
 * the extension's bearer token, which is a credential for capturing postings,
 * and not a signed URL, which would be a second way in to keep in step with
 * the first.
 *
 * It answers for the current revision of a posting and for nothing else. A
 * revision is addressable, but the screen shows the current one, and an
 * endpoint that took a revision id would answer about revisions no screen asks
 * about.
 *
 * **No PDF is a 404.** An empty 200 would have the browser render a blank
 * frame, which reads as a CV with nothing in it rather than as a CV that was
 * never produced.
 *
 * Nothing is logged. Reading a screen is not an event, and a line per look
 * would bury the ones that matter.
 */
class PostingPdfController
{
    /**
     * The route's name inside the panel, which is the name the panel is given
     * it under: Filament prefixes a panel's own routes with `filament.` and
     * the panel's id.
     */
    public const ROUTE = 'postings.cv';

    /**
     * Where the review screen points its frame.
     */
    public static function urlFor(Posting $posting): string
    {
        return route('filament.'.ReviewPanelProvider::ID.'.'.self::ROUTE, ['posting' => $posting]);
    }

    public function __invoke(Posting $posting, PdfStore $pdfs, CvFilename $filenames): Response
    {
        $revision = $posting->currentRevision;
        $pdf = $revision === null ? null : $pdfs->read($revision);

        if ($pdf === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // The filename is what a browser's own "save" offers, so the
            // inline copy and the download are named the same thing.
            'Content-Disposition' => 'inline; filename="'.$filenames->pdf($posting).'"',
        ]);
    }
}
