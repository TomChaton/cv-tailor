<?php

namespace Tests\Feature\Revisions;

use App\Generation\StoreGenerationOutput;
use App\Jobs\RenderValidatedRevision;
use App\Jobs\ValidateGeneratedRevision;
use App\Llm\LlmReply;
use App\Llm\LlmResponse;
use App\Llm\TokenUsage;
use App\Models\MasterCvVersion;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Postings\PostingStatus;
use App\Rendering\PdfConversionFailed;
use App\Rendering\PdfConverter;
use App\Rendering\Testing\FakePdfConverter;
use App\Revisions\RevisionSource;
use App\Revisions\RevisionStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

/**
 * The two links that carry a revision from a stored result to a PDF.
 *
 * The queue connection is `sync` here, so a dispatch runs the job: what these
 * tests assert is that nothing in the chain has to be started by hand, which
 * is the whole point of it.
 */
class RevisionChainTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use SelectionFixtures;

    private ?MasterCvVersion $version = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdfs');
    }

    public function test_a_stored_result_reaches_ready_with_a_pdf_and_nothing_is_run_by_hand(): void
    {
        $revision = $this->submitted();

        $this->store()->handle($revision, $this->response($this->selection()));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Ready, $stored->status);
        $this->assertSame([], $stored->validation_json);
        $this->assertSame('revisions/'.$revision->id.'.pdf', $stored->pdf_path);
        Storage::disk('pdfs')->assertExists('revisions/'.$revision->id.'.pdf');

        // The tracker the owner reads is the end of the chain, not a step
        // somebody has to take afterwards.
        $this->assertSame(PostingStatus::Ready, $revision->posting()->firstOrFail()->status);
    }

    /**
     * A reader following one revision through the log sees each step in order.
     */
    public function test_the_log_follows_one_revision_from_its_stored_result_to_ready(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->store()->handle($this->submitted(), $this->response($this->selection()));

        $this->assertSame([
            'generation.stored',
            'revision.validated',
            'revision.resolved',
            'revision.rendered',
            'posting.ready',
        ], $log->events());
    }

    /**
     * `validated` is where a revision with a blocking finding stops. It is not
     * `failed`: it was produced, and the owner regenerates from it.
     */
    public function test_a_blocking_finding_stops_the_chain_at_validated_and_renders_nothing(): void
    {
        $revision = $this->submitted();

        $this->store()->handle($revision, $this->response($this->selectionWithSummary(
            'Backend engineer running dispatch services on Kubernetes.',
        )));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Validated, $stored->status);
        $this->assertNotSame([], $stored->validation_json);
        $this->assertNull($stored->pdf_path);
        $this->assertSame([], $this->converter()->converted);
        $this->assertSame([], Storage::disk('pdfs')->allFiles());
    }

    public function test_the_log_shows_where_a_blocked_revision_stopped(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->store()->handle($this->submitted(), $this->response($this->selectionWithSummary(
            'Backend engineer running dispatch services on Kubernetes.',
        )));

        $this->assertSame([
            'generation.stored',
            'revision.validated',
            'revision.validation_blocked',
        ], $log->events());
    }

    /**
     * A retried job dispatches the same work a second time. One set of
     * findings, one of each rendered file, and no attempt to write over a
     * revision that has since been rendered.
     */
    public function test_a_duplicate_validation_dispatch_produces_one_set_of_findings_and_one_pdf(): void
    {
        $revision = $this->generated($this->selection());

        $this->validate($revision);
        $this->validate($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Ready, $stored->status);
        $this->assertCount(1, $this->converter()->converted);
        $this->assertSame(
            ['revisions/'.$revision->id.'.docx', 'revisions/'.$revision->id.'.pdf'],
            Storage::disk('pdfs')->allFiles(),
        );
    }

    public function test_a_duplicate_validation_dispatch_on_a_blocked_revision_keeps_one_set_of_findings(): void
    {
        $revision = $this->generated($this->selectionWithSummary(
            'Backend engineer running dispatch services on Kubernetes.',
        ));

        $this->validate($revision);
        $this->validate($revision);

        $findings = $revision->fresh()?->validation_json;

        $this->assertIsArray($findings);
        $this->assertCount(1, $findings);
    }

    public function test_a_duplicate_render_dispatch_produces_one_pdf(): void
    {
        $revision = $this->validated();

        $this->render($revision);
        $this->render($revision);

        $this->assertSame(RevisionStatus::Ready, $revision->fresh()?->status);
        $this->assertCount(1, $this->converter()->converted);
        $this->assertSame(
            ['revisions/'.$revision->id.'.docx', 'revisions/'.$revision->id.'.pdf'],
            Storage::disk('pdfs')->allFiles(),
        );
    }

    /**
     * Nothing about the chain reads where the content came from: words the
     * owner typed are no better evidenced than words a model wrote.
     */
    public function test_a_manual_edit_takes_the_same_path(): void
    {
        $revision = $this->generated($this->selection(), RevisionSource::ManualEdit);

        $this->validate($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Ready, $stored->status);
        Storage::disk('pdfs')->assertExists('revisions/'.$revision->id.'.pdf');
    }

    /**
     * One job per revision, so a revision that cannot be rendered is one
     * failed job rather than a pipeline that stopped. This one runs the worker
     * rather than the `sync` connection, because the behaviour under test is
     * the worker's: it takes the next job after a failure.
     */
    public function test_a_render_that_fails_leaves_its_revision_validated_and_takes_the_next_one(): void
    {
        $this->converter()->fail(PdfConversionFailed::status(502));
        config(['queue.default' => 'database']);

        $first = $this->validated();
        $second = $this->validated();

        $bus = $this->app->make(Dispatcher::class);
        $bus->dispatch(new RenderValidatedRevision($first));
        $bus->dispatch(new RenderValidatedRevision($second));

        // The worker's own memory ceiling, raised past the one it defaults to:
        // it measures the whole PHP process, which here is the test suite, so
        // a default-sized worker stops after one job and the second revision
        // looks like a converter that was never called.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 2048]);

        foreach ([$first, $second] as $revision) {
            $stored = $revision->fresh();

            $this->assertNotNull($stored);
            $this->assertSame(RevisionStatus::Validated, $stored->status);
            $this->assertStringContainsString('502', (string) $stored->error);
            $this->assertNull($stored->pdf_path);
        }

        // One conversion each: a render is not retried by itself. A converter
        // that is down leaves the revision for the owner to act on.
        $this->assertCount(2, $this->converter()->converted);
        $this->assertSame([], Storage::disk('pdfs')->allFiles());
        $this->assertSame(2, DB::table('failed_jobs')->count());
    }

    /**
     * A revision at `generated`, as a stored result leaves one.
     *
     * @param  array<string, mixed>  $selection
     */
    private function generated(array $selection, RevisionSource $source = RevisionSource::Generated): Revision
    {
        return Revision::factory()->create([
            'master_cv_version_id' => $this->version()->id,
            'source' => $source,
            'status' => RevisionStatus::Generated,
            'output_json' => $selection,
        ]);
    }

    /**
     * A revision validation has passed, waiting for its PDF.
     */
    private function validated(): Revision
    {
        return Revision::factory()->create([
            'master_cv_version_id' => $this->version()->id,
            'status' => RevisionStatus::Validated,
            'output_json' => $this->selection(),
            'validation_json' => [],
        ]);
    }

    /**
     * A revision as the submitter leaves one, on the posting `cv:queue` left
     * behind it: `queued`, and looking at this revision.
     */
    private function submitted(): Revision
    {
        $revision = Revision::factory()->status(RevisionStatus::Submitted)->create([
            'master_cv_version_id' => $this->version()->id,
        ]);

        $revision->posting()->firstOrFail()->fill([
            'status' => PostingStatus::Queued,
            'current_revision_id' => $revision->id,
        ])->save();

        return $revision;
    }

    /**
     * The stored version every revision in a test names. A version's hash is
     * unique, so one CV cannot be stored twice, and two revisions of the same
     * CV share the row.
     */
    private function version(): MasterCvVersion
    {
        return $this->version ??= MasterCvVersion::factory()->content($this->fixture())->create();
    }

    private function validate(Revision $revision): void
    {
        $this->app->call([new ValidateGeneratedRevision($revision), 'handle']);
    }

    private function render(Revision $revision): void
    {
        $this->app->call([new RenderValidatedRevision($revision), 'handle']);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function response(array $selection): LlmResponse
    {
        return LlmResponse::priced(
            new LlmReply(
                reportedModel: 'claude-opus-5-20260101',
                text: (string) json_encode($selection, JSON_THROW_ON_ERROR),
                stopReason: 'end_turn',
                usage: new TokenUsage(
                    inputTokens: 412,
                    outputTokens: 286,
                    cacheWriteTokens: 0,
                    cacheReadTokens: 0,
                ),
            ),
            requestedModel: 'claude-opus-5',
            costMicros: 2500,
        );
    }

    private function store(): StoreGenerationOutput
    {
        return $this->app->make(StoreGenerationOutput::class);
    }

    private function converter(): FakePdfConverter
    {
        /** @var FakePdfConverter $converter */
        $converter = $this->app->make(PdfConverter::class);

        return $converter;
    }
}
