<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\MasterCv as MasterCvPage;
use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\InspectMasterCv;
use App\MasterCv\Lint\Finding;
use App\Models\MasterCv;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class MasterCvPageTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_is_not_reachable_without_signing_in(): void
    {
        $this->get(MasterCvPage::getUrl())->assertRedirect('/review/login');
    }

    public function test_it_is_in_the_navigation(): void
    {
        $this->signIn();

        $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertSee(__('master-cv.title'))
            ->assertSee(MasterCvPage::getUrl());
    }

    public function test_the_editor_holds_the_stored_markdown(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        Livewire::test(MasterCvPage::class)
            ->assertSet('data.markdown', $this->fixture());
    }

    /**
     * A fresh installation has no CV, and an empty editor is the whole of
     * what there is to say about it.
     */
    public function test_an_installation_with_no_cv_shows_an_empty_editor(): void
    {
        $this->signIn();

        Livewire::test(MasterCvPage::class)
            ->assertSuccessful()
            ->assertSet('data.markdown', '')
            ->assertDontSee(__('master-cv.problems'))
            ->assertDontSee(__('master-cv.passes'))
            ->assertActionHidden('download')
            ->assertActionHidden('addIds');
    }

    public function test_it_reports_when_the_cv_was_saved_the_version_and_the_counts(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        $counts = $this->parseFixture()->counts();

        $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertSee(__('master-cv.saved'))
            ->assertSee(__('master-cv.version'))
            ->assertSee($this->parseFixture()->hash())
            ->assertSee(__('master-cv.counts.roles'))
            ->assertSee(__('master-cv.counts.sub_bullets'))
            ->assertSeeInOrder([__('master-cv.counts.skills_written'), (string) $counts['skills_written']])
            ->assertSee(__('master-cv.counts.skills_derived'));
    }

    public function test_a_cv_that_passes_lint_shows_no_problems(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/minimal.md');

        $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertSee(__('master-cv.problems'))
            ->assertSee(__('master-cv.passes'))
            ->assertDontSee(__('master-cv.notes'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function brokenFixtures(): array
    {
        return [
            'a duplicate ID' => ['broken-duplicate-id.md', 'ids', 'line 42'],
            'a missing ID' => ['broken-missing-id.md', 'ids', 'line 42'],
            'a missing section' => ['broken-missing-section.md', 'sections', ''],
            'a skills row naming no role' => ['broken-skill-names-no-role.md', 'skills_index_references', 'line 32'],
        ];
    }

    #[DataProvider('brokenFixtures')]
    public function test_a_problem_is_shown_under_its_check_with_its_line(
        string $fixture,
        string $check,
        string $where,
    ): void {
        $this->signIn();
        $this->storeMasterCv('master-cv/'.$fixture);

        $report = app(InspectMasterCv::class)->handle();
        $problem = $report->problemsByCheck()[$check][0];

        $response = $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertDontSee(__('master-cv.passes'))
            ->assertSee(__('master-cv.checks.'.$check))
            ->assertSee($problem->message);

        if ($where !== '') {
            $response->assertSee($where);
        }
    }

    /**
     * The button is beside the problem, so the page says nothing about how to
     * fix it. `cv:lint` is where the command belongs, because a terminal has
     * no button.
     */
    public function test_a_problem_with_a_button_beside_it_names_no_command(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertSee('This achievement has no ID.')
            ->assertSee(__('master-cv.actions.add_ids'))
            ->assertDontSee('artisan');
    }

    public function test_notes_are_shown_apart_from_the_problems(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        $notes = app(InspectMasterCv::class)->handle()->notes();

        $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertSeeInOrder([
                __('master-cv.problems'),
                __('master-cv.passes'),
                __('master-cv.notes'),
                __('master-cv.checks.block_labels'),
                $notes[0]->message,
            ]);
    }

    /**
     * The line number is in the page to be acted on, so the finding that
     * carries it is a button: reachable by keyboard, operable with it, and
     * answering the only question the number was being asked.
     *
     * The selection itself is the browser's and is checked by hand.
     */
    public function test_a_problem_is_a_control_that_selects_the_line_it_names(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $problem = app(InspectMasterCv::class)->handle()->problemsByCheck()['ids'][0];
        $this->assertNotNull($problem->line);

        $control = $this->control($this->render(), $problem->line);

        $this->assertStringContainsString('type="button"', $control);
        $this->assertStringContainsString(e($this->findingText($problem)), $control);
    }

    /**
     * A note names a line for the same reason a problem does, and the fact
     * that it does not fail the CV has nothing to do with finding it.
     */
    public function test_a_note_is_a_control_that_selects_the_line_it_names(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        $note = app(InspectMasterCv::class)->handle()->notes()[0];
        $this->assertNotNull($note->line);

        $control = $this->control($this->render(), $note->line);

        $this->assertStringContainsString('type="button"', $control);
        $this->assertStringContainsString(e($this->findingText($note)), $control);
    }

    public function test_a_cv_with_no_problems_and_no_notes_has_no_controls(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/minimal.md');

        $this->assertSame([], $this->controls($this->render()));
    }

    /**
     * There is nothing to select, so there is nothing to press. A control
     * that did nothing would be worse than the sentence it replaced.
     */
    public function test_a_finding_that_names_no_line_is_not_a_control(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-missing-section.md');

        $problem = app(InspectMasterCv::class)->handle()->problemsByCheck()['sections'][0];
        $this->assertNull($problem->line);

        $html = $this->render();

        $this->assertStringContainsString(e($problem->message), $html);

        foreach ($this->controls($html) as $control) {
            $this->assertStringNotContainsString(e($problem->message), $control);
        }
    }

    public function test_saving_stores_what_was_typed(): void
    {
        $this->signIn();

        Livewire::test(MasterCvPage::class)
            ->set('data.markdown', $this->fixture('master-cv/minimal.md'))
            ->callAction('save')
            ->assertHasNoErrors();

        $this->assertSame($this->fixture('master-cv/minimal.md'), MasterCv::query()->sole()->markdown);
    }

    public function test_saving_re_lints_without_leaving_the_page(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/minimal.md');

        Livewire::test(MasterCvPage::class)
            ->assertSee(__('master-cv.passes'))
            ->set('data.markdown', str_replace(
                ' {#wrenbury-freight.2}',
                '',
                $this->fixture('master-cv/minimal.md'),
            ))
            ->callAction('save')
            ->assertDontSee(__('master-cv.passes'))
            ->assertSee(__('master-cv.checks.ids'))
            ->assertActionVisible('addIds');
    }

    /**
     * A draft. Refusing to store a CV with a problem in it would make that
     * problem unfixable.
     */
    public function test_a_cv_that_fails_lint_still_saves(): void
    {
        $this->signIn();

        Livewire::test(MasterCvPage::class)
            ->set('data.markdown', $this->fixture('master-cv/broken-missing-id.md'))
            ->callAction('save');

        $this->assertSame(
            $this->fixture('master-cv/broken-missing-id.md'),
            MasterCv::query()->sole()->markdown,
        );
    }

    /**
     * Saving is free and unversioned: a version is what a generation used.
     */
    public function test_saving_records_no_version(): void
    {
        $this->signIn();

        Livewire::test(MasterCvPage::class)
            ->set('data.markdown', $this->fixture())
            ->callAction('save');

        $this->assertDatabaseCount('master_cv_versions', 0);
    }

    public function test_the_download_is_exactly_what_was_saved(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        Livewire::test(MasterCvPage::class)
            ->callAction('download')
            ->assertFileDownloaded(__('master-cv.download_filename'), $this->fixture());
    }

    public function test_the_ids_download_is_withheld_when_nothing_is_missing_an_id(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        Livewire::test(MasterCvPage::class)
            ->assertActionVisible('save')
            ->assertActionVisible('download')
            ->assertActionHidden('addIds');
    }

    public function test_the_ids_download_is_offered_when_an_id_is_missing(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        Livewire::test(MasterCvPage::class)->assertActionVisible('addIds');
    }

    public function test_the_ids_download_is_what_cv_id_produces_for_the_same_cv(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $expected = (new AssignMasterCvIds($this->parser()))
            ->handle($this->fixture('master-cv/broken-missing-id.md'))
            ->content;

        Livewire::test(MasterCvPage::class)
            ->callAction('addIds')
            ->assertFileDownloaded(__('master-cv.download_with_ids_filename'), $expected);
    }

    /**
     * The cell has a closed set of valid answers, so the problem about it
     * comes with the way to answer. A problem this page cannot fix shows no
     * control suggesting otherwise.
     */
    public function test_only_a_where_used_problem_carries_a_choice(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-skill-names-no-role.md');

        Livewire::test(MasterCvPage::class)
            ->assertActionVisible($this->whereUsed('php-8x-83'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixturesWithOtherProblems(): array
    {
        return [
            'a duplicate ID' => ['broken-duplicate-id.md'],
            'a missing ID' => ['broken-missing-id.md'],
            'a missing section' => ['broken-missing-section.md'],
            'an unknown block label' => ['../master-cv.md'],
        ];
    }

    #[DataProvider('fixturesWithOtherProblems')]
    public function test_no_other_finding_carries_a_choice(string $fixture): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/'.$fixture);

        $this->assertStringNotContainsString(
            "mountAction('whereUsed",
            $this->render(),
        );
    }

    /**
     * The list comes from the parse, so an answer that is not a role or
     * project in the document is not on offer at all.
     */
    public function test_the_modal_names_the_skill_and_offers_every_role_and_project(): void
    {
        $this->signIn();
        $this->storeMarkdown($this->withBrokenRustRow());

        $page = Livewire::test(MasterCvPage::class)
            ->mountAction($this->whereUsed('rust'))
            ->assertActionMounted($this->whereUsed('rust'));

        $page->assertMountedActionModalSee([
            'Rust',
            (string) __('master-cv.where_used'),
            'Harlequin Transit',
            'Northgate Tickets',
            'Pelham Civic Data',
            'Larkspur Media',
            'Independent: Fathom Index',
            'Independent: Quayside Charts',
        ]);

        $page->assertMountedActionModalDontSee('Pentland Haulage');
    }

    /**
     * A problem of this class is a cell where nothing resolves, so there is
     * nothing to carry over. Which parts of a cell do resolve is
     * `WhereUsedChoices`' own test.
     */
    public function test_a_cell_that_resolves_to_nothing_pre_selects_nothing(): void
    {
        $this->signIn();
        $this->storeMarkdown($this->withBrokenRustRow());

        Livewire::test(MasterCvPage::class)
            ->mountAction($this->whereUsed('rust'))
            ->assertActionDataSet(['where_used' => []]);
    }

    public function test_confirming_rewrites_that_row_and_re_lints(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-skill-names-no-role.md');

        Livewire::test(MasterCvPage::class)
            ->assertSee(__('master-cv.checks.skills_index_references'))
            ->callAction($this->whereUsed('php-8x-83'), ['where_used' => ['wrenbury-freight']])
            ->assertHasNoActionErrors()
            ->assertSee(__('master-cv.passes'));

        // One line apart from the broken fixture, and that line is the row.
        $this->assertSame(
            $this->fixture('master-cv/minimal.md'),
            MasterCv::query()->sole()->markdown,
        );
    }

    /**
     * The CV's own rules turn on commercial experience against personal work,
     * so a chosen project says it is one.
     */
    public function test_an_independent_project_is_written_as_one(): void
    {
        $this->signIn();
        $this->storeMarkdown($this->withBrokenRustRow());

        Livewire::test(MasterCvPage::class)
            ->callAction($this->whereUsed('rust'), ['where_used' => ['fathom-index']]);

        $this->assertSame(
            str_replace(
                '| Rust | Independent: Fathom Index (indexer) |',
                '| Rust | Independent: Fathom Index |',
                $this->fixture(),
            ),
            MasterCv::query()->sole()->markdown,
        );
    }

    /**
     * A legitimate answer for a skill its author has not placed yet.
     */
    public function test_confirming_with_nothing_selected_is_accepted(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-skill-names-no-role.md');

        Livewire::test(MasterCvPage::class)
            ->callAction($this->whereUsed('php-8x-83'), ['where_used' => []])
            ->assertHasNoActionErrors();

        $this->assertSame(
            str_replace(
                '| PHP 8.x (8.3) | Pentland Haulage |',
                '| PHP 8.x (8.3) | |',
                $this->fixture('master-cv/broken-skill-names-no-role.md'),
            ),
            MasterCv::query()->sole()->markdown,
        );
    }

    /**
     * The set on offer is the whole of what may be written, so an answer from
     * anywhere else reaches the document through nothing: the form refuses
     * what the options do not hold, and the cell is composed from the parse
     * rather than from what came back.
     */
    public function test_an_answer_the_document_does_not_offer_never_reaches_it(): void
    {
        $this->signIn();
        $this->storeMasterCv('master-cv/broken-skill-names-no-role.md');

        Livewire::test(MasterCvPage::class)
            ->callAction($this->whereUsed('php-8x-83'), ['where_used' => ['pentland-haulage']])
            ->assertHasActionErrors();

        $this->assertSame(
            $this->fixture('master-cv/broken-skill-names-no-role.md'),
            MasterCv::query()->sole()->markdown,
        );
    }

    /**
     * An unrecognised block label is allowed and the behaviour is already
     * sensible, so the note informs and offers nothing.
     */
    public function test_a_block_label_note_names_the_recognised_labels_and_offers_no_fix(): void
    {
        $this->signIn();
        $this->storeMasterCv();

        $this->get(MasterCvPage::getUrl())
            ->assertSuccessful()
            ->assertSee(
                'It knows Context, Tech stack, Achievements &amp; projects, Responsibilities and Projects.',
                escape: false,
            );
    }

    private function whereUsed(string $skill): TestAction
    {
        return TestAction::make('whereUsed_'.$skill)->schemaComponent();
    }

    /**
     * The fictional CV with one skills-index row naming an employer it does
     * not have — one line apart from a CV that passes lint.
     */
    private function withBrokenRustRow(): string
    {
        return str_replace(
            '| Rust | Independent: Fathom Index (indexer) |',
            '| Rust | Pentland Haulage |',
            $this->fixture(),
        );
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }

    private function render(): string
    {
        return $this->get(MasterCvPage::getUrl())->assertSuccessful()->getContent() ?: '';
    }

    /**
     * The control that selects a given line, markup and all.
     */
    private function control(string $html, int $line): string
    {
        $matched = preg_match(
            '#<button[^>]*x-on:click="selectLine\('.$line.'\)"[^>]*>.*?</button>#s',
            $html,
            $matches,
        );

        $this->assertSame(1, $matched, 'No control selects line '.$line.'.');

        return $matches[0];
    }

    /**
     * Every control on the page that selects a line.
     *
     * @return list<string>
     */
    private function controls(string $html): array
    {
        preg_match_all('#<button[^>]*x-on:click="selectLine\(\d+\)"[^>]*>.*?</button>#s', $html, $matches);

        return $matches[0];
    }

    private function findingText(Finding $finding): string
    {
        return (string) __('master-cv.finding', [
            'line' => $finding->line,
            'message' => $finding->message,
        ]);
    }
}
