<?php

namespace Tests\Feature\Review;

use App\Filament\Resources\Postings\Pages\ViewPosting;
use App\Filament\Resources\Postings\PostingResource;
use App\Http\Controllers\Review\PostingPdfController;
use App\MasterCv\Skill;
use App\Models\MasterCvVersion;
use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use App\Validation\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;
use Tests\TestCase;

class PostingScreenTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use SelectionFixtures;

    private ?MasterCvVersion $fixtureVersion = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdfs');
    }

    public function test_it_is_not_reachable_without_signing_in(): void
    {
        $posting = Posting::factory()->create();

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertRedirect('/review/login');
    }

    /**
     * Registering the page is also what makes a row on the list lead here:
     * Filament points a record at its resource's view page when there is one.
     */
    public function test_the_list_leads_to_it(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create();

        $this->get(PostingResource::getUrl())
            ->assertSuccessful()
            ->assertSee(PostingResource::getUrl('view', ['record' => $posting]));
    }

    public function test_the_job_is_the_pages_title(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create(['title' => 'Staff Engineer']);

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee('Staff Engineer');
    }

    // ------------------------------------------------------------------
    // A posting with no revision: the job, and the decision about it.
    // ------------------------------------------------------------------

    public function test_a_posting_with_no_revision_shows_the_job_description(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create([
            'description' => "What the role is.\nWhat it needs.",
            'company' => 'Northwind Logistics',
            'location' => 'Leeds',
            'salary_text' => '£90,000',
        ]);

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee(__('postings.sections.description'))
            ->assertSee('What the role is.')
            ->assertSee('What it needs.')
            ->assertSee('Northwind Logistics')
            ->assertSee('Leeds')
            ->assertSee('£90,000');
    }

    /**
     * The description is a page's own words. It is escaped before its line
     * breaks are put back, so nothing a listing carries is rendered as markup.
     */
    public function test_the_description_is_escaped_before_its_line_breaks_are_kept(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create([
            'description' => "First line.\n<script>alert(1)</script>",
        ]);

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSeeHtml('First line.<br />')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    /**
     * Hidden text in a listing is sometimes aimed at tools rather than
     * readers, which the owner needs to know before trusting what was
     * extracted.
     */
    public function test_the_hidden_text_warning_shows_where_capture_set_it(): void
    {
        $this->signIn();

        $warned = Posting::factory()->withHiddenText()->create();
        $ordinary = Posting::factory()->create();

        $this->get(PostingResource::getUrl('view', ['record' => $warned]))
            ->assertSee(__('postings.hidden_text'));

        $this->get(PostingResource::getUrl('view', ['record' => $ordinary]))
            ->assertDontSee(__('postings.hidden_text'));
    }

    public function test_whether_a_cover_letter_was_asked_for_is_stated_either_way(): void
    {
        $this->signIn();

        $asked = Posting::factory()->withCoverLetterRequested()->create();
        $not = Posting::factory()->create();

        $this->get(PostingResource::getUrl('view', ['record' => $asked]))
            ->assertSee(__('postings.columns.cover_letter'))
            ->assertSee(__('postings.cover_letter_requested'));

        $this->get(PostingResource::getUrl('view', ['record' => $not]))
            ->assertSee(__('postings.cover_letter_not_requested'));
    }

    /**
     * The screen has to open either way. `php artisan cv:status` is where a
     * posting waiting on extraction is told apart from one whose extraction
     * failed.
     */
    public function test_a_posting_extraction_has_not_answered_for_still_opens(): void
    {
        $this->signIn();

        $posting = Posting::factory()->awaitingExtraction()->create();

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee(__('postings.sections.description'))
            ->assertSee(__('postings.absent'));
    }

    public function test_want_is_offered_on_a_captured_posting_and_nowhere_else(): void
    {
        $this->signIn();

        $captured = Posting::factory()->status(PostingStatus::Captured)->create();
        $wanted = Posting::factory()->status(PostingStatus::Wanted)->create();

        $this->page($captured)->assertActionVisible('want');
        $this->page($wanted)->assertActionHidden('want');
    }

    public function test_want_marks_the_posting_and_stops_offering_itself(): void
    {
        $this->signIn();

        $posting = Posting::factory()->status(PostingStatus::Captured)->create();

        $this->page($posting)
            ->callAction('want')
            ->assertActionHidden('want');

        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }

    public function test_discard_is_offered_everywhere_but_discarded_and_asks_first(): void
    {
        $this->signIn();

        $posting = Posting::factory()->status(PostingStatus::Captured)->create();
        $discarded = Posting::factory()->status(PostingStatus::Discarded)->create();

        $page = $this->page($posting)
            ->assertActionVisible('discard')
            ->mountAction('discard');

        $page->assertMountedActionModalSee([
            __('postings.discard_confirmation.heading'),
            __('postings.discard_confirmation.confirm'),
        ]);

        $this->assertSame(PostingStatus::Captured, $posting->refresh()->status);

        $page->callMountedAction()->assertActionHidden('discard');

        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);

        $this->page($discarded)->assertActionHidden('discard');
    }

    // ------------------------------------------------------------------
    // A posting with a current revision: the review surface.
    // ------------------------------------------------------------------

    public function test_the_pdf_is_embedded_beside_the_job_description(): void
    {
        $this->signIn();

        $posting = $this->reviewable();

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee(__('postings.sections.description'))
            ->assertSee(__('postings.sections.cv'))
            ->assertSeeHtml('<iframe src="'.PostingPdfController::urlFor($posting).'"');
    }

    /**
     * A revision a blocking finding stopped has findings to read and no PDF to
     * read them against.
     */
    public function test_a_revision_with_no_pdf_embeds_nothing(): void
    {
        $this->signIn();

        $posting = $this->reviewable(pdf: false);

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertDontSeeHtml('<iframe');
    }

    public function test_the_checklist_renders_every_requirement_in_order(): void
    {
        $this->signIn();

        $posting = $this->reviewable(output: $this->selection(['jd_analysis' => [
            $this->requirementOf('Nice and met', 'nice', 'met'),
            $this->requirementOf('Must and met', 'must', 'met'),
            $this->requirementOf('Must and gap', 'must', 'gap'),
            $this->requirementOf('Must and partial', 'must', 'partial'),
        ]]));

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee(__('postings.sections.requirements'))
            ->assertSeeInOrder([
                'Must and gap',
                'Must and partial',
                'Must and met',
                'Nice and met',
            ])
            ->assertSee(__('postings.priorities.must'))
            ->assertSee(__('postings.priorities.nice'))
            ->assertSee(__('postings.requirement_statuses.gap'))
            ->assertSee(__('postings.requirement_statuses.partial'))
            ->assertSee(__('postings.requirement_statuses.met'))
            ->assertSee('One sentence for the owner.');
    }

    public function test_the_checklist_resolves_evidence_to_the_master_cvs_own_words(): void
    {
        $this->signIn();

        $posting = $this->reviewable(output: $this->selection(['jd_analysis' => [
            $this->requirementOf('Modern PHP', 'must', 'met', ['harlequin-transit.6']),
            $this->requirementOf('Kubernetes', 'must', 'gap'),
        ]]));

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            // Through the renderer the CV template uses: a bullet carries a
            // bold lead-in by the format's own convention, and the asterisks
            // are not something the owner's CV says.
            ->assertSeeHtml('<strong>PHP 7.4 to 8.3 modernisation.</strong>')
            ->assertDontSee('**PHP 7.4 to 8.3 modernisation.**');
    }

    /**
     * **A revision is pinned to a version, and showing today's wording beside
     * an older revision would misrepresent what was sent.**
     */
    public function test_evidence_resolves_against_the_revisions_own_master_cv_version(): void
    {
        $this->signIn();

        $then = "# Fictional Person\n\n## Employment history\n\n"
            ."### Example Ltd {#example}\n\n- The wording that was sent {#example.thing}\n";
        $now = "# Fictional Person\n\n## Employment history\n\n"
            ."### Example Ltd {#example}\n\n- The wording nobody has sent {#example.thing}\n";

        // The current CV says one thing; the version the revision points at
        // says another, and the revision's own version is what is read.
        $this->storeMarkdown($now);
        MasterCvVersion::factory()->content($now)->create();
        $older = MasterCvVersion::factory()->content($then)->create();

        $posting = $this->reviewable(
            output: ['jd_analysis' => [
                $this->requirementOf('Did a thing', 'must', 'met', ['example.thing']),
            ]],
            version: $older,
        );

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee('The wording that was sent')
            ->assertDontSee('The wording nobody has sent');
    }

    public function test_the_checklist_shows_a_skill_and_a_role_by_name(): void
    {
        $this->signIn();

        $posting = $this->reviewable(output: $this->selection(['jd_analysis' => [
            $this->requirementOf('Backend experience', 'must', 'met', [
                'harlequin-transit',
                Skill::idFor('PHP 8.x (8.3)'),
            ]),
        ]]));

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee('Harlequin Transit — Senior Backend Engineer', escape: false)
            ->assertSee('PHP 8.x (8.3)');
    }

    /**
     * A finding is the deterministic checks' own account of the revision, and
     * a summary of it would be a weaker claim than the thing itself.
     */
    public function test_findings_render_as_stored_with_their_severity_blocking_first(): void
    {
        $this->signIn();

        $posting = $this->reviewable(findings: [
            [
                'check' => 'word_budget',
                'severity' => Severity::Advisory->value,
                'field' => null,
                'ids' => [],
                'message' => 'The selection runs to 900 words against a target of 700.',
            ],
            [
                'check' => 'ids_resolve',
                'severity' => Severity::Blocking->value,
                'field' => 'summary.supports[0]',
                'ids' => ['nothing-of-the-sort'],
                'message' => 'nothing-of-the-sort is not in this master CV.',
            ],
        ]);

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee(__('postings.sections.findings'))
            ->assertSeeInOrder([
                __('postings.severities.blocking'),
                'nothing-of-the-sort is not in this master CV.',
                __('postings.severities.advisory'),
                'The selection runs to 900 words against a target of 700.',
            ]);
    }

    public function test_a_revision_with_no_findings_says_nothing_about_them(): void
    {
        $this->signIn();

        $posting = $this->reviewable(findings: []);

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertDontSee(__('postings.sections.findings'));
    }

    public function test_review_notes_render_as_the_model_wrote_them(): void
    {
        $this->signIn();

        $posting = $this->reviewable(output: $this->selection([
            'review_notes' => [
                'gaps' => ['The advert asks for Kubernetes and the CV does not mention it.'],
                'judgement_calls' => ['Led with the dispatch API over the search work.'],
                'promotions' => [[
                    'id' => 'larkspur-media',
                    'from' => 'omitted',
                    'to' => 'condensed',
                    'reason' => 'The advert asks for agency experience.',
                ]],
            ],
        ]));

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertSee(__('postings.sections.notes'))
            ->assertSee(__('postings.sections.gaps'))
            ->assertSee('The advert asks for Kubernetes and the CV does not mention it.')
            ->assertSee(__('postings.sections.judgement_calls'))
            ->assertSee('Led with the dispatch API over the search work.')
            ->assertSee(__('postings.sections.promotions'))
            // The role is named from the version the revision is pinned to.
            ->assertSee('Larkspur Media')
            ->assertSee('The advert asks for agency experience.');
    }

    public function test_a_revision_with_no_notes_says_nothing_about_them(): void
    {
        $this->signIn();

        $posting = $this->reviewable();

        $this->get(PostingResource::getUrl('view', ['record' => $posting]))
            ->assertSuccessful()
            ->assertDontSee(__('postings.sections.notes'));
    }

    public function test_the_cv_can_be_downloaded_as_well_as_read(): void
    {
        $this->signIn();

        $posting = $this->reviewable();

        $this->page($posting)
            ->assertActionVisible('download')
            ->callAction('download')
            ->assertFileDownloaded();
    }

    // ------------------------------------------------------------------
    // The tracker.
    // ------------------------------------------------------------------

    public function test_mark_reviewed_moves_a_ready_posting_and_nothing_else(): void
    {
        $this->signIn();

        $posting = $this->reviewable(status: PostingStatus::Ready);

        $this->page($posting)
            ->assertActionVisible('markReviewed')
            ->callAction('markReviewed')
            ->assertActionHidden('markReviewed');

        $this->assertSame(PostingStatus::Reviewed, $posting->refresh()->status);
        $this->assertNull($posting->applied_revision_id);
    }

    public function test_mark_reviewed_is_not_offered_where_the_posting_has_moved_on(): void
    {
        $this->signIn();

        foreach ([PostingStatus::Reviewed, PostingStatus::Applied, PostingStatus::Discarded] as $status) {
            $this->page($this->reviewable(status: $status))->assertActionHidden('markReviewed');
        }
    }

    /**
     * **It records `applied_revision_id` as well as the status.** A tracker
     * that knows a job was applied for but not with which CV is half a record.
     */
    public function test_mark_applied_records_the_revision_that_was_sent(): void
    {
        $this->signIn();

        $posting = $this->reviewable(status: PostingStatus::Ready);

        $this->page($posting)
            ->assertActionVisible('markApplied')
            ->callAction('markApplied')
            ->assertActionHidden('markApplied');

        $posting->refresh();

        $this->assertSame(PostingStatus::Applied, $posting->status);
        $this->assertSame($posting->current_revision_id, $posting->applied_revision_id);
    }

    /**
     * Applying without marking the CV read first is an ordinary thing to do.
     */
    public function test_mark_applied_is_offered_on_a_reviewed_posting_too(): void
    {
        $this->signIn();

        $posting = $this->reviewable(status: PostingStatus::Reviewed);

        $this->page($posting)->callAction('markApplied');

        $this->assertSame(PostingStatus::Applied, $posting->refresh()->status);
    }

    public function test_neither_tracker_action_is_offered_on_an_applied_or_discarded_posting(): void
    {
        $this->signIn();

        foreach ([PostingStatus::Applied, PostingStatus::Discarded] as $status) {
            $this->page($this->reviewable(status: $status))
                ->assertActionHidden('markReviewed')
                ->assertActionHidden('markApplied');
        }
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  list<array<string, mixed>>|null  $findings
     */
    private function reviewable(
        PostingStatus $status = PostingStatus::Ready,
        ?array $output = null,
        ?array $findings = [],
        bool $pdf = true,
        ?MasterCvVersion $version = null,
    ): Posting {
        $posting = Posting::factory()->status($status)->create();

        $revision = Revision::factory()
            ->status(RevisionStatus::Validated)
            ->create([
                'posting_id' => $posting->id,
                'master_cv_version_id' => ($version ?? $this->fixtureVersion())->id,
                'output_json' => $output,
                'validation_json' => $findings,
            ]);

        if ($pdf) {
            $path = 'revisions/'.$revision->id.'.pdf';

            Storage::disk('pdfs')->put($path, '%PDF-1.7 a tailored CV');

            $revision->update(['pdf_path' => $path, 'status' => RevisionStatus::Ready]);
        }

        $posting->fill(['current_revision_id' => $revision->id])->save();

        return $posting;
    }

    /**
     * The fictional CV as a stored version, made once per test: a version is
     * identified by its parse, so two rows holding the same CV are one row.
     */
    private function fixtureVersion(): MasterCvVersion
    {
        return $this->fixtureVersion ??= MasterCvVersion::factory()
            ->content($this->fixture())
            ->create();
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    private function requirementOf(
        string $requirement,
        string $priority,
        string $status,
        array $evidence = [],
    ): array {
        return [
            'requirement' => $requirement,
            'priority' => $priority,
            'status' => $status,
            'evidence' => $evidence,
            'note' => 'One sentence for the owner.',
        ];
    }

    /**
     * @return Testable<ViewPosting>
     */
    private function page(Posting $posting): Testable
    {
        return Livewire::test(ViewPosting::class, ['record' => $posting->getKey()]);
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }
}
