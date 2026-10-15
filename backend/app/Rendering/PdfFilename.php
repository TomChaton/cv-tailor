<?php

namespace App\Rendering;

use App\Models\Posting;
use Illuminate\Support\Str;

/**
 * The name a posting's CV reaches an employer under.
 *
 * It is named for the job rather than for the row: a posting's id means
 * nothing to anybody outside this database. Capture omits a company rather
 * than guessing one, so a posting without one is ordinary and still gets a
 * filename — under the id, which is the only other thing that tells two files
 * apart. A company whose name is all punctuation slugs to nothing and takes
 * the same route.
 *
 * One class rather than one per surface. The file the owner downloads and the
 * file a browser is handed inline are the same document, and two copies of
 * this would eventually name it two things.
 */
final readonly class PdfFilename
{
    public function for(Posting $posting): string
    {
        $company = Str::slug((string) $posting->company);

        if ($company === '') {
            return (string) __('postings.pdf_filename', ['posting' => $posting->id]);
        }

        return (string) __('postings.pdf_filename_for_company', ['company' => $company]);
    }
}
