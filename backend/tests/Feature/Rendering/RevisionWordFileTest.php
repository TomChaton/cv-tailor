<?php

namespace Tests\Feature\Rendering;

use App\Models\MasterCvVersion;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Rendering\DocxWriter;
use App\Rendering\PdfConverter;
use App\Rendering\RenderRevision;
use App\Rendering\ResolvedDocument;
use App\Rendering\Testing\FakePdfConverter;
use App\Revisions\RevisionStatus;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;
use Tests\Concerns\WordDocuments;
use Tests\TestCase;

/**
 * The Word file one render produces.
 *
 * It is read back out of the package rather than asserted on as markup: what
 * matters is what the owner's word processor will find in it, and a template
 * can emit a heading-shaped paragraph that is not a heading.
 */
class RevisionWordFileTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use SelectionFixtures;
    use WordDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdfs');
    }

    public function test_a_rendered_revision_has_a_word_file_beside_its_pdf(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Ready, $stored->status);
        $this->assertSame('revisions/'.$revision->id.'.docx', $stored->docx_path);
        Storage::disk('pdfs')->assertExists('revisions/'.$revision->id.'.docx');
    }

    /**
     * A revision has exactly one Word file. The path comes from the revision's
     * ID, so a second render replaces the file rather than adding one.
     */
    public function test_re_rendering_replaces_the_word_file_rather_than_adding_one(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);
        $this->render()->handle($revision->fresh() ?? $revision);

        $this->assertSame(
            ['revisions/'.$revision->id.'.docx', 'revisions/'.$revision->id.'.pdf'],
            Storage::disk('pdfs')->allFiles(),
        );
    }

    /**
     * The one thing the package must be: a zip a word processor can open,
     * carrying the parts that make its headings headings and its lists lists.
     */
    public function test_the_file_is_a_word_package_with_its_styles_and_numbering(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $parts = $this->partsOf($this->wordFile($revision));

        $this->assertSame(
            [
                '[Content_Types].xml',
                '_rels/.rels',
                'word/_rels/document.xml.rels',
                'word/styles.xml',
                'word/numbering.xml',
                'word/document.xml',
            ],
            array_keys($parts),
        );

        foreach ($parts as $path => $xml) {
            $this->assertStringStartsWith('<?xml version="1.0"', $xml, $path.' is not XML.');
            $this->assertTrue($this->parses($xml), $path.' is not well-formed XML.');
        }

        // The styles the document's paragraphs name, under the ids the word
        // processor knows its own built-in styles by.
        foreach (['Title', 'Subtitle', 'Heading1', 'Heading2', 'ListParagraph'] as $style) {
            $this->assertStringContainsString('w:styleId="'.$style.'"', $parts['word/styles.xml']);
        }

        $this->assertStringContainsString('<w:name w:val="heading 1"/>', $parts['word/styles.xml']);
        $this->assertStringContainsString('<w:numFmt w:val="bullet"/>', $parts['word/numbering.xml']);
    }

    public function test_the_section_headings_are_heading_styles(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $paragraphs = $this->paragraphsOf($this->wordFile($revision));

        $headings = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['style'] === 'Heading1') {
                $headings[] = $paragraph['text'];
            }
        }

        $this->assertSame([
            __('cv.profile'),
            __('cv.skills'),
            __('cv.experience'),
            __('cv.projects'),
            __('cv.awards'),
        ], $headings);
    }

    /**
     * Section headings are template copy, so they come from the catalogue. The
     * assertion is on a changed catalogue value rather than on the literal, or
     * it would pass just as well for a heading hard-coded in the template.
     */
    public function test_the_headings_come_from_the_language_catalogue(): void
    {
        /** @var Translator $translator */
        $translator = $this->app->make(Translator::class);
        $translator->addLines(['cv.experience' => 'Rôles tenus'], 'en_GB');

        $revision = $this->revision();

        $this->render()->handle($revision);

        $this->assertContains(
            ['style' => 'Heading1', 'bullet' => null, 'text' => 'Rôles tenus'],
            $this->paragraphsOf($this->wordFile($revision)),
        );
    }

    /**
     * A role is a heading of its own with its dates in the paragraph under it,
     * so the owner can see at a glance which employer a bullet belongs to
     * while they edit.
     */
    public function test_a_role_is_a_heading_with_its_dates_under_it(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $paragraphs = $this->paragraphsOf($this->wordFile($revision));
        $texts = array_column($paragraphs, 'text');

        $heading = array_search('Harlequin Transit — Senior Backend Engineer', $texts, true);

        $this->assertIsInt($heading);
        $this->assertSame('Heading2', $paragraphs[$heading]['style']);
        $this->assertSame('September 2021 – present', $paragraphs[$heading + 1]['text']);
    }

    public function test_the_bullets_are_list_items_and_keep_their_nesting(): void
    {
        $revision = $this->revision(selection: $this->selectionWithItems('harlequin-transit', [
            $this->verbatim('harlequin-transit.4'),
            $this->verbatim('harlequin-transit.4.1'),
        ]));

        $this->render()->handle($revision);

        $paragraphs = $this->paragraphsOf($this->wordFile($revision));

        $bullets = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['bullet'] !== null) {
                $bullets[] = ['bullet' => $paragraph['bullet'], 'text' => $paragraph['text']];
            }
        }

        // A bullet is a list item of the word processor's own numbering, which
        // is what lets the owner add one with Enter and demote one with Tab.
        $this->assertContains(
            ['bullet' => 0, 'text' => 'Dispatch API (designed and built).'],
            $bullets,
        );
        $this->assertContains(
            [
                'bullet' => 1,
                'text' => 'Replaced a nightly batch hand-off with a synchronous REST API on Slim 4, '
                    .'because a driver is waiting on the result.',
            ],
            $bullets,
        );

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['bullet'] !== null) {
                $this->assertSame('ListParagraph', $paragraph['style']);
            }
        }
    }

    /**
     * A master CV's bullets carry a bold lead-in by the format's own
     * convention, so a Word file that printed the text as it stands would put
     * asterisks on a CV.
     */
    public function test_the_inline_markup_of_a_bullet_becomes_emphasis(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $document = $this->partsOf($this->wordFile($revision))['word/document.xml'];

        $this->assertStringNotContainsString('**', $document);
        $this->assertStringContainsString(
            '<w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Dispatch API (designed and built).</w:t>',
            preg_replace('/\s*\n\s*/', '', $document) ?? '',
        );
    }

    /**
     * The header is the parsed contact section's, which never reaches the
     * model, and it carries only the fields the presentation policy names.
     */
    public function test_the_header_comes_from_the_contact_section(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $paragraphs = $this->paragraphsOf($this->wordFile($revision));
        $texts = array_column($paragraphs, 'text');

        $this->assertSame(['style' => 'Title', 'bullet' => null, 'text' => 'Jordan Avery'], $paragraphs[0]);
        $this->assertStringContainsString('jordan.avery@example.com', implode("\n", $texts));
        $this->assertStringNotContainsString('14 Beech Grove', implode("\n", $texts));
        $this->assertStringNotContainsString('07700 900123', implode("\n", $texts));
    }

    /**
     * **The two renderings are one document.** Both read `resolved_json` and
     * nothing else, so the file the owner edits and the file they send cannot
     * disagree about what the CV says.
     *
     * The comparison is on the letters and digits of each block in order,
     * because the two carry the same content in different casings of
     * punctuation: a separator the PDF's stylesheet supplies in CSS is a
     * character in the Word file, and neither is part of what the CV claims.
     */
    public function test_the_word_file_says_what_the_pdf_says_in_the_same_order(): void
    {
        $revision = $this->revision();

        $this->render()->handle($revision);

        $this->assertSame(
            $this->contentOf($this->converter()->lastHtml()),
            array_values(array_filter(array_map(
                fn (array $paragraph): string => $this->comparable($paragraph['text']),
                $this->paragraphsOf($this->wordFile($revision)),
            ), fn (string $text): bool => $text !== '')),
        );
    }

    /**
     * The editable copy is a convenience and must not cost the owner the thing
     * they can send.
     */
    public function test_a_word_file_that_cannot_be_written_leaves_the_revision_its_pdf(): void
    {
        $this->app->instance(DocxWriter::class, new class implements DocxWriter
        {
            public function write(ResolvedDocument $document): string
            {
                throw new RuntimeException('no package today');
            }
        });

        $revision = $this->revision();

        $this->render()->handle($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Ready, $stored->status);
        $this->assertSame('revisions/'.$revision->id.'.pdf', $stored->pdf_path);
        $this->assertNull($stored->docx_path);
        $this->assertNull($stored->error);
        $this->assertSame(['revisions/'.$revision->id.'.pdf'], Storage::disk('pdfs')->allFiles());
    }

    public function test_it_warns_when_the_word_file_alone_fails(): void
    {
        $this->app->instance(DocxWriter::class, new class implements DocxWriter
        {
            public function write(ResolvedDocument $document): string
            {
                throw new RuntimeException('no package today');
            }
        });

        $revision = $this->revision();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('revision.resolved', Mockery::any());
        $logger->shouldReceive('log')
            ->once()
            ->with('revision.rendered', Mockery::on(function (array $context): bool {
                // One line still says what the render produced: the PDF, and
                // no Word file.
                $this->assertNull($context['docx_path']);
                $this->assertNull($context['docx_bytes']);

                return true;
            }));
        $logger->shouldReceive('warning')
            ->once()
            ->with('revision.docx_failed', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame(RuntimeException::class, $context['failure']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->render()->handle($revision);
    }

    public function test_the_rendered_line_carries_the_word_files_path_and_size(): void
    {
        $revision = $this->revision();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('revision.resolved', Mockery::any());
        $logger->shouldReceive('log')
            ->once()
            ->with('revision.rendered', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame('revisions/'.$revision->id.'.docx', $context['docx_path']);
                $this->assertGreaterThan(0, $context['docx_bytes']);

                return true;
            }));
        $logger->shouldNotReceive('warning');

        $this->instance(EventLogger::class, $logger);

        $this->render()->handle($revision);
    }

    /**
     * A log line carries counts, sizes and paths. The document itself is the
     * CV, and a log line that would be embarrassing in a public bug report is
     * a bug.
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
                    'Dispatch',
                    json_encode($context, JSON_THROW_ON_ERROR),
                );

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->render()->handle($revision);
    }

    /**
     * @param  ?array<string, mixed>  $selection
     */
    private function revision(?array $selection = null): Revision
    {
        return Revision::factory()->create([
            'master_cv_version_id' => MasterCvVersion::factory()->content($this->fixture()),
            'status' => RevisionStatus::Validated,
            'output_json' => $selection ?? $this->selection(),
            'validation_json' => [],
        ]);
    }

    private function wordFile(Revision $revision): string
    {
        return (string) Storage::disk('pdfs')->get('revisions/'.$revision->id.'.docx');
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
