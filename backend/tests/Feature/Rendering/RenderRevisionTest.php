<?php

namespace Tests\Feature\Rendering;

use App\Models\MasterCvVersion;
use App\Models\Posting;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Postings\PostingStatus;
use App\Rendering\PdfConversionFailed;
use App\Rendering\PdfConverter;
use App\Rendering\RenderRevision;
use App\Rendering\Testing\FakePdfConverter;
use App\Revisions\RevisionStatus;
use App\Validation\RevisionIsNotRenderable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;
use Tests\TestCase;

class RenderRevisionTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use SelectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdfs');
    }

    public function test_a_validated_revision_becomes_a_pdf_and_reaches_ready(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Ready, $stored->status);
        $this->assertSame('revisions/'.$revision->id.'.pdf', $stored->pdf_path);
        Storage::disk('pdfs')->assertExists('revisions/'.$revision->id.'.pdf');
    }

    public function test_the_resolved_document_is_stored_before_the_pdf_is_made(): void
    {
        $revision = $this->revision();
        $this->converter()->fail(PdfConversionFailed::empty());

        try {
            $this->render()->handle($revision);
        } catch (PdfConversionFailed) {
            // The failure is the point of the next test.
        }

        $resolved = $revision->fresh()->resolved_json ?? [];

        $this->assertIsArray($resolved['header'] ?? null);
        $this->assertSame('Jordan Avery', $resolved['header']['name']);
    }

    /**
     * `failed` is for a revision that could not be produced. This one was
     * produced correctly and only the conversion did not happen, so the next
     * attempt has everything it needs.
     */
    public function test_a_converter_failure_leaves_the_revision_validated_with_the_error_recorded(): void
    {
        $revision = $this->revision();
        $this->converter()->fail(PdfConversionFailed::status(502));

        try {
            $this->render()->handle($revision);
            $this->fail('A converter failure must not pass silently.');
        } catch (PdfConversionFailed) {
            // Expected: the caller is a pipeline step and has to know.
        }

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Validated, $stored->status);
        $this->assertNull($stored->pdf_path);
        $this->assertStringContainsString('502', (string) $stored->error);
        $this->assertSame([], Storage::disk('pdfs')->allFiles());
    }

    public function test_a_blocking_finding_refuses_the_render(): void
    {
        $revision = $this->revision([[
            'check' => 'do_not_claim',
            'severity' => 'blocking',
            'field' => 'summary.text',
            'ids' => [],
            'message' => 'Kubernetes is in the master CV\'s "Do not claim" section.',
        ]]);

        $this->expectException(RevisionIsNotRenderable::class);

        try {
            $this->render()->handle($revision);
        } finally {
            $this->assertSame(RevisionStatus::Validated, $revision->fresh()?->status);
            $this->assertSame([], Storage::disk('pdfs')->allFiles());
            $this->assertSame([], $this->converter()->converted);
        }
    }

    /**
     * A null findings list is not an empty one, and "nobody checked" is not a
     * reason to render.
     */
    public function test_a_revision_nothing_has_validated_is_refused(): void
    {
        $revision = $this->revision(null);

        $this->expectException(RevisionIsNotRenderable::class);

        try {
            $this->render()->handle($revision);
        } finally {
            $this->assertSame([], Storage::disk('pdfs')->allFiles());
        }
    }

    /**
     * A revision has exactly one PDF. The path comes from the revision's ID,
     * so a second render replaces the file rather than adding one.
     */
    public function test_re_rendering_replaces_the_pdf_rather_than_adding_one(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);
        $this->render()->handle($revision->fresh() ?? $revision);

        $this->assertSame(['revisions/'.$revision->id.'.pdf'], Storage::disk('pdfs')->allFiles());
        $this->assertCount(2, $this->converter()->converted);
    }

    public function test_an_advisory_finding_does_not_stop_the_render(): void
    {
        $revision = $this->revision([[
            'check' => 'word_budget',
            'severity' => 'advisory',
            'field' => null,
            'ids' => [],
            'message' => 'Longer than the target.',
        ]]);

        $this->render()->handle($revision);

        $this->assertSame(RevisionStatus::Ready, $revision->fresh()?->status);
    }

    /**
     * The posting's `ready` is denormalised from its current revision, and the
     * step that puts a revision there is what keeps the two in step. Without
     * it the tracker the owner reads understates what is finished.
     */
    public function test_a_rendered_revision_takes_its_posting_to_ready(): void
    {
        $revision = $this->revision();
        $posting = $this->tracker($revision, PostingStatus::Queued);

        $this->render()->handle($revision);

        $this->assertSame(PostingStatus::Ready, $posting->refresh()->status);
    }

    public function test_a_render_leaves_a_reviewed_posting_where_the_owner_put_it(): void
    {
        $revision = $this->revision();
        $posting = $this->tracker($revision, PostingStatus::Reviewed);

        $this->render()->handle($revision);

        $this->assertSame(RevisionStatus::Ready, $revision->fresh()?->status);
        $this->assertSame(PostingStatus::Reviewed, $posting->refresh()->status);
    }

    public function test_the_header_in_the_rendered_page_comes_from_the_contact_section(): void
    {
        $this->render()->handle($this->revision());

        $html = $this->converter()->lastHtml();

        $this->assertStringContainsString('Jordan Avery', $html);
        $this->assertStringContainsString('jordan.avery@example.com', $html);
        // The policy names the header's fields, so a postal address the author
        // keeps for application forms stays off the page.
        $this->assertStringNotContainsString('14 Beech Grove', $html);
        // The bold lead-in of a master CV bullet is emphasis, not asterisks on
        // a CV.
        $this->assertStringContainsString('<strong>Dispatch API (designed and built).</strong>', $html);
    }

    /**
     * Section headings are template copy, so they come from the catalogue. The
     * assertion is on a changed catalogue value rather than on the literal, or
     * it would pass just as well for a heading hard-coded in the template.
     */
    public function test_section_headings_come_from_the_language_catalogue(): void
    {
        /** @var Translator $translator */
        $translator = $this->app->make(Translator::class);
        $translator->addLines(['cv.experience' => 'Rôles tenus'], 'en_GB');

        $this->render()->handle($this->revision());

        $this->assertStringContainsString('Rôles tenus', $this->converter()->lastHtml());
    }

    public function test_it_logs_the_resolve_counts_and_the_pdf_size(): void
    {
        $revision = $this->revision();
        $this->converter()->withPages(3);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('revision.resolved', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame($revision->master_cv_version_id, $context['master_cv_version_id']);
                $this->assertSame(5, $context['roles_included']);
                $this->assertSame(1, $context['roles_condensed']);
                $this->assertSame(1, $context['roles_omitted']);
                $this->assertSame(6, $context['items']);
                $this->assertSame(1, $context['reworded_items']);

                return true;
            }));
        $logger->shouldReceive('log')
            ->once()
            ->with('revision.rendered', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame('revisions/'.$revision->id.'.pdf', $context['pdf_path']);
                $this->assertGreaterThan(0, $context['bytes']);
                $this->assertSame(3, $context['pages']);

                return true;
            }));
        $logger->shouldNotReceive('warning');

        $this->instance(EventLogger::class, $logger);

        $this->render()->handle($revision);
    }

    public function test_it_warns_when_a_render_is_refused_and_names_the_checks(): void
    {
        $revision = $this->revision([[
            'check' => 'items_belong_to_their_role',
            'severity' => 'blocking',
            'field' => 'roles[0].items[1].id',
            'ids' => ['northgate-tickets.1'],
            'message' => 'An achievement is shown under the wrong employer.',
        ]]);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('revision.render_failed', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame('validation blocked it', $context['reason']);
                $this->assertSame(['items_belong_to_their_role'], $context['checks']);

                return true;
            }));
        $logger->shouldNotReceive('log');

        $this->instance(EventLogger::class, $logger);

        try {
            $this->render()->handle($revision);
        } catch (RevisionIsNotRenderable) {
            // The warning is what this test is about.
        }
    }

    public function test_it_warns_when_the_converter_fails(): void
    {
        $revision = $this->revision();
        $this->converter()->fail(PdfConversionFailed::unreachable(new \RuntimeException('no route')));

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('revision.resolved', Mockery::any());
        $logger->shouldReceive('warning')
            ->once()
            ->with('revision.render_failed', Mockery::on(function (array $context): bool {
                $this->assertSame('it could not be reached', $context['reason']);
                $this->assertSame([], $context['checks']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        try {
            $this->render()->handle($revision);
        } catch (PdfConversionFailed) {
            // The warning is what this test is about.
        }
    }

    /**
     * A log line carries counts and sizes. The document itself is the CV, and a
     * log line that would be embarrassing in a public bug report is a bug.
     */
    public function test_no_log_line_carries_cv_content(): void
    {
        $revision = $this->revision();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->twice()
            ->with(Mockery::type('string'), Mockery::on(function (array $context): bool {
                $this->assertStringNotContainsString(
                    'Jordan',
                    json_encode($context, JSON_THROW_ON_ERROR),
                );
                $this->assertStringNotContainsString(
                    'Elasticsearch',
                    json_encode($context, JSON_THROW_ON_ERROR),
                );

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->render()->handle($revision);
    }

    /**
     * @param  ?list<array<string, mixed>>  $findings
     */
    private function revision(?array $findings = []): Revision
    {
        return Revision::factory()->create([
            'master_cv_version_id' => MasterCvVersion::factory()->content($this->fixture()),
            'status' => RevisionStatus::Validated,
            'output_json' => $this->selection(),
            'validation_json' => $findings,
        ]);
    }

    /**
     * A posting that is actually looking at this revision, at the status the
     * owner left it.
     */
    private function tracker(Revision $revision, PostingStatus $status): Posting
    {
        $posting = $revision->posting()->firstOrFail();

        $posting->fill([
            'status' => $status,
            'current_revision_id' => $revision->id,
        ])->save();

        return $posting;
    }

    private function render(): RenderRevision
    {
        return $this->app->make(RenderRevision::class);
    }

    private function converter(): FakePdfConverter
    {
        /** @var FakePdfConverter $converter */
        $converter = $this->app->make(PdfConverter::class);

        return $converter;
    }
}
