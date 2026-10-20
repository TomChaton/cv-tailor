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
 * One class rather than one per surface or one per format. The file the owner
 * downloads, the file a browser is handed inline and the copy the owner edits
 * are one document, so they differ in an extension and in nothing else; two
 * copies of this would eventually name them two things.
 */
final readonly class CvFilename
{
    public function pdf(Posting $posting): string
    {
        return $this->named($posting, 'pdf');
    }

    public function word(Posting $posting): string
    {
        return $this->named($posting, 'docx');
    }

    private function named(Posting $posting, string $extension): string
    {
        $company = Str::slug((string) $posting->company);

        if ($company === '') {
            return (string) __('postings.cv_filename', [
                'posting' => $posting->id,
                'extension' => $extension,
            ]);
        }

        return (string) __('postings.cv_filename_for_company', [
            'company' => $company,
            'extension' => $extension,
        ]);
    }
}
