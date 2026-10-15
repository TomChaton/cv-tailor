<?php

namespace Tests\Feature\Review;

use App\Http\Controllers\Review\PostingPdfController;
use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PostingPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = '%PDF-1.7 a tailored CV';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdfs');
    }

    public function test_the_owner_is_served_the_current_revisions_pdf_inline(): void
    {
        $posting = $this->postingWithPdf(['company' => 'Northwind Logistics']);

        $this->actingAs(User::factory()->create())
            ->get(PostingPdfController::urlFor($posting))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="cv-northwind-logistics.pdf"')
            ->assertSee(self::PDF, escape: false);
    }

    /**
     * The PDF is the owner's, so it is behind the panel's own login rather
     * than in front of anybody who knows the URL.
     */
    public function test_a_guest_is_sent_to_the_login(): void
    {
        $posting = $this->postingWithPdf();

        $this->fetch($posting)->assertRedirect('/review/login');
    }

    /**
     * The extension's token is a credential for capturing postings. It is not
     * a way to read the CVs they produce.
     */
    public function test_the_extensions_bearer_token_does_not_reach_it(): void
    {
        $posting = $this->postingWithPdf();
        $token = User::factory()->create()->createToken('extension')->plainTextToken;

        $this->assertSame(1, PersonalAccessToken::query()->count());

        $response = $this->fetch($posting, ['Authorization' => 'Bearer '.$token]);

        $response->assertRedirect('/review/login');
        $this->assertStringNotContainsString(self::PDF, $response->getContent() ?: '');
    }

    /**
     * An empty 200 would have the browser render a blank frame, which reads as
     * a CV with nothing in it rather than as a CV that was never produced.
     */
    public function test_a_revision_with_no_pdf_is_a_404(): void
    {
        $posting = Posting::factory()->status(PostingStatus::Queued)->create();

        $revision = Revision::factory()
            ->status(RevisionStatus::Validated)
            ->create(['posting_id' => $posting->id]);

        $posting->fill(['current_revision_id' => $revision->id])->save();

        $this->actingAs(User::factory()->create())
            ->get(PostingPdfController::urlFor($posting))
            ->assertNotFound();
    }

    public function test_a_posting_with_no_revision_is_a_404(): void
    {
        $posting = Posting::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(PostingPdfController::urlFor($posting))
            ->assertNotFound();
    }

    /**
     * `pdf_path` is the system's own statement that a PDF exists; the disk is
     * a named volume, and a volume that has been replaced is a missing file.
     */
    public function test_a_path_with_no_file_behind_it_is_a_404(): void
    {
        $posting = $this->postingWithPdf();

        Storage::disk('pdfs')->delete('revisions/'.$posting->current_revision_id.'.pdf');

        $this->actingAs(User::factory()->create())
            ->get(PostingPdfController::urlFor($posting))
            ->assertNotFound();
    }

    /**
     * Capture omits a company rather than guessing one, and a posting without
     * one is ordinary.
     */
    public function test_a_posting_with_no_company_is_named_for_its_id(): void
    {
        $posting = $this->postingWithPdf(['company' => null]);

        $this->actingAs(User::factory()->create())
            ->get(PostingPdfController::urlFor($posting))
            ->assertHeader('Content-Disposition', 'inline; filename="cv-'.$posting->id.'.pdf"');
    }

    /**
     * A browser's own request, headers written out.
     *
     * `get()` and the JSON helpers set an `Accept` of their own, and that
     * header is what the authentication middleware chooses its branch on: a
     * guest asking for JSON raises a failure where a guest asking for a
     * document is sent wherever guests are configured to go. The thing under
     * test here is where a refused request lands, so the headers the real
     * client sends are written out.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function fetch(Posting $posting, array $headers = []): TestResponse
    {
        return $this->call(
            'GET',
            PostingPdfController::urlFor($posting),
            [],
            [],
            [],
            $this->transformHeadersToServerVars(['Accept' => 'text/html', ...$headers]),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function postingWithPdf(array $attributes = []): Posting
    {
        $posting = Posting::factory()->status(PostingStatus::Ready)->create($attributes);

        $revision = Revision::factory()
            ->status(RevisionStatus::Validated)
            ->create(['posting_id' => $posting->id, 'validation_json' => []]);

        $path = 'revisions/'.$revision->id.'.pdf';

        Storage::disk('pdfs')->put($path, self::PDF);

        $revision->update(['pdf_path' => $path, 'status' => RevisionStatus::Ready]);
        $posting->fill(['current_revision_id' => $revision->id])->save();

        return $posting;
    }
}
