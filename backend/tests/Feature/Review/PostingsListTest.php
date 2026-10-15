<?php

namespace Tests\Feature\Review;

use App\Filament\Resources\Postings\Pages\ListPostings;
use App\Filament\Resources\Postings\PostingResource;
use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostingsListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdfs');
    }

    public function test_it_is_not_reachable_without_signing_in(): void
    {
        $this->get(PostingResource::getUrl())->assertRedirect('/review/login');
    }

    public function test_it_is_in_the_navigation_under_the_word_on_screen(): void
    {
        $this->signIn();

        $this->get(PostingResource::getUrl())
            ->assertSuccessful()
            ->assertSee(__('postings.label_plural'))
            ->assertSee(PostingResource::getUrl());
    }

    /**
     * Capture is free and speculative, so `discarded` is the status that
     * accumulates. Every other one is live work or the record of work.
     */
    public function test_it_opens_on_every_posting_but_the_discarded_ones(): void
    {
        $this->signIn();

        $ofInterest = [];

        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Discarded) {
                $ofInterest[] = Posting::factory()->status($status)->create();
            }
        }

        $discarded = Posting::factory()->status(PostingStatus::Discarded)->create();

        Livewire::test(ListPostings::class)
            ->assertCanSeeTableRecords($ofInterest)
            ->assertCanNotSeeTableRecords([$discarded])
            ->assertCountTableRecords(count($ofInterest));
    }

    public function test_the_newest_posting_is_first(): void
    {
        $this->signIn();

        $oldest = Posting::factory()->create(['created_at' => now()->subDays(2)]);
        $middle = Posting::factory()->create(['created_at' => now()->subDay()]);
        $newest = Posting::factory()->create(['created_at' => now()]);

        Livewire::test(ListPostings::class)
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_it_shows_what_the_capture_found(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create([
            'title' => 'Staff Engineer',
            'company' => 'Northwind Logistics',
            'location' => 'Leeds',
            'salary_text' => '£90,000',
            'source_site' => 'example-board.test',
        ]);

        $separator = __('postings.qualifier_separator');

        Livewire::test(ListPostings::class)
            ->assertTableColumnStateSet('title', 'Staff Engineer', $posting)
            ->assertTableColumnStateSet('source_site', 'example-board.test', $posting)
            ->assertTableColumnHasDescription(
                'title',
                'Northwind Logistics'.$separator.'Leeds'.$separator.'£90,000',
                $posting,
            );
    }

    /**
     * The record the title names is the posting, not the advert, and the
     * posting's own screen is where deciding happens.
     */
    public function test_the_title_opens_the_postings_own_screen(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create();
        $screen = PostingResource::getUrl('view', ['record' => $posting]);

        Livewire::test(ListPostings::class)
            ->assertTableColumnExists(
                'title',
                fn (TextColumn $column): bool => $column->getUrl() === $screen
                    && ! $column->shouldOpenUrlInNewTab(),
                $posting,
            )
            // The anchor is Filament's, not a template's, and it stays in this
            // tab: the owner is going further into the application.
            ->assertSeeHtml('href="'.$screen.'"')
            ->assertDontSeeHtml('href="'.$screen.'" target="_blank"');
    }

    /**
     * The way in belongs to the row rather than to a cell. A posting the
     * capture could not name has no title to hang a link on and still has to
     * be reachable.
     */
    public function test_a_row_opens_the_posting_it_names(): void
    {
        $this->signIn();

        $named = Posting::factory()->create();
        $unnamed = Posting::factory()->awaitingExtraction()->create();

        $table = $this->listTable();

        foreach ([$named, $unnamed] as $posting) {
            $this->assertSame(
                PostingResource::getUrl('view', ['record' => $posting]),
                $table->getRecordUrl($posting),
            );
        }

        Livewire::test(ListPostings::class)
            ->assertSeeHtml('href="'.PostingResource::getUrl('view', ['record' => $unnamed]).'"');
    }

    /**
     * None of them is a link, so a decision taken from a row stays on the
     * list: the row is a way into the posting and the buttons on it are not.
     */
    public function test_the_record_actions_do_not_navigate(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create();

        foreach ($this->listTable()->getRecordActions() as $action) {
            if (! $action instanceof Action) {
                $this->fail('A record action is not an action.');
            }

            $this->assertNull(
                $action->record($posting)->getUrl(),
                $action->getName().' navigates instead of acting',
            );
        }
    }

    /**
     * The row's one outbound link, and the only way from the list to the
     * board. It is on every row because `url` is the one field every capture
     * carries, so a posting extraction could not name still reaches its
     * advert. A new tab, because the owner is working through a list and must
     * not lose their place in it.
     */
    public function test_the_source_links_out_to_the_posting_on_every_row(): void
    {
        $this->signIn();

        $named = Posting::factory()->create(['url' => 'https://example-board.test/jobs/1']);
        $unnamed = Posting::factory()
            ->awaitingExtraction()
            ->create(['url' => 'https://example-board.test/jobs/2']);

        Livewire::test(ListPostings::class)
            ->assertTableColumnExists(
                'source_site',
                fn (TextColumn $column): bool => $column->getUrl() === 'https://example-board.test/jobs/1'
                    && $column->shouldOpenUrlInNewTab(),
                $named,
            )
            ->assertTableColumnExists(
                'source_site',
                fn (TextColumn $column): bool => $column->getUrl() === 'https://example-board.test/jobs/2'
                    && $column->shouldOpenUrlInNewTab(),
                $unnamed,
            );
    }

    /**
     * The absent placeholder is a label for nothing, so it is not dressed as a
     * link of its own. The row under it is that posting's way in.
     */
    public function test_a_posting_the_capture_could_not_name_carries_no_link_on_its_title(): void
    {
        $this->signIn();

        $posting = Posting::factory()->awaitingExtraction()->create();

        Livewire::test(ListPostings::class)
            ->assertTableColumnExists(
                'title',
                fn (TextColumn $column): bool => $column->getUrl() === null,
                $posting,
            )
            ->assertSee(__('postings.absent'));
    }

    /**
     * Neither the page nor extraction guesses, and the list must not undo
     * that: a blank cell cannot be told from a value that is blank. The title
     * is the column's value, so it is the field that gets the placeholder.
     */
    public function test_a_posting_the_capture_could_not_name_shows_as_absent(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create(['title' => null]);

        Livewire::test(ListPostings::class)
            ->assertTableColumnStateSet('title', null, $posting)
            ->assertTableColumnExists(
                'title',
                fn (TextColumn $text): bool => $text->getPlaceholder() === __('postings.absent'),
                $posting,
            )
            ->assertSee(__('postings.absent'));
    }

    /**
     * The facts that qualify a title are one line, so a field the capture did
     * not find is left out of it rather than placeheld: a line of dashes is
     * noise, and the line already says what is known.
     *
     * @param  array<string, string|null>  $found
     * @param  list<string>  $expected  what the line reads, in order
     */
    #[DataProvider('qualifierLines')]
    public function test_the_qualifying_line_carries_only_what_the_capture_found(
        array $found,
        array $expected,
    ): void {
        $this->signIn();

        $posting = Posting::factory()->create($found);

        $component = Livewire::test(ListPostings::class);

        if ($expected === []) {
            $component->assertTableColumnExists(
                'title',
                fn (TextColumn $column): bool => $column->getDescriptionBelow() === null,
                $posting,
            );

            return;
        }

        $component->assertTableColumnHasDescription(
            'title',
            implode((string) __('postings.qualifier_separator'), $expected),
            $posting,
        );
    }

    /**
     * The separator is assembled in the test rather than here: a data provider
     * runs before the application is booted, so there is no translator to ask.
     *
     * @return iterable<string, array{array<string, string|null>, list<string>}>
     */
    public static function qualifierLines(): iterable
    {
        $all = [
            'company' => 'Northwind Logistics',
            'location' => 'Leeds',
            'salary_text' => '£90,000',
        ];

        yield 'everything' => [$all, ['Northwind Logistics', 'Leeds', '£90,000']];

        yield 'no company' => [
            [...$all, 'company' => null],
            ['Leeds', '£90,000'],
        ];

        yield 'no location' => [
            [...$all, 'location' => null],
            ['Northwind Logistics', '£90,000'],
        ];

        yield 'no salary' => [
            [...$all, 'salary_text' => null],
            ['Northwind Logistics', 'Leeds'],
        ];

        yield 'only a company' => [
            ['company' => 'Northwind Logistics', 'location' => null, 'salary_text' => null],
            ['Northwind Logistics'],
        ];

        yield 'nothing the capture could qualify it with' => [
            ['company' => null, 'location' => null, 'salary_text' => null],
            [],
        ];
    }

    /**
     * A posting whose extraction has not run yet, and one whose extraction
     * failed, are both rows with nothing in them: the placeholder where the
     * title would be, and no qualifying line at all. `cv:status` is where the
     * difference between the two is visible.
     */
    public function test_a_posting_awaiting_extraction_lists_with_nothing_but_its_placeholder(): void
    {
        $this->signIn();

        $posting = Posting::factory()->awaitingExtraction()->create();

        Livewire::test(ListPostings::class)
            ->assertCanSeeTableRecords([$posting])
            ->assertTableColumnStateSet('title', null, $posting)
            ->assertTableColumnExists(
                'title',
                fn (TextColumn $column): bool => $column->getDescriptionBelow() === null,
                $posting,
            )
            ->assertSee(__('postings.absent'));
    }

    /**
     * A posting the owner recognises from its URL is still worth a CV, so the
     * gate is open whatever extraction made of the page.
     */
    public function test_a_posting_awaiting_extraction_is_still_markable_as_wanted(): void
    {
        $this->signIn();

        $posting = Posting::factory()->awaitingExtraction()->create();

        Livewire::test(ListPostings::class)
            ->callAction(TestAction::make('want')->table($posting));

        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }

    public function test_it_shows_the_status(): void
    {
        $this->signIn();

        $posting = Posting::factory()->wanted()->create();

        Livewire::test(ListPostings::class)
            ->assertTableColumnFormattedStateSet('status', __('postings.statuses.wanted'), $posting);
    }

    /**
     * The list is for choosing which posting to spend a generation on, and
     * what a posting needs beyond that is the posting's own screen. So neither
     * the cover letter request nor the hidden-text warning is a column here,
     * though a capture still records both. What qualifies a title rides on the
     * title column rather than taking columns of its own.
     */
    public function test_the_list_carries_what_a_choice_needs_and_nothing_else(): void
    {
        $this->signIn();

        $this->assertSame([
            'title',
            'source_site',
            'created_at',
            'status',
        ], array_keys($this->listTable()->getColumns()));
    }

    /**
     * The qualifying line is a description on the title column, not a layout
     * component: a layout component in `columns()` costs the whole list its
     * table — no headings and no click-to-sort — and reading a list down is
     * what those are for.
     */
    public function test_the_list_renders_a_table_the_reader_can_sort_from_its_headings(): void
    {
        $this->signIn();

        $table = $this->listTable();

        // Filament's own flag for a row laid out as content rather than as
        // cells, which is what a layout component in `columns()` turns on.
        $this->assertFalse($table->hasColumnsLayout());

        $title = $table->getColumn('title');

        if ($title === null) {
            $this->fail('The list has no title column.');
        }

        $this->assertTrue($title->isSortable());

        $first = Posting::factory()->create(['title' => 'Ansible Engineer']);
        $last = Posting::factory()->create(['title' => 'Zone Lead']);

        Livewire::test(ListPostings::class)
            // The headings are what the reader clicks, so they have to be on
            // the page for the sort to be reachable at all.
            ->assertSee(__('postings.columns.title'))
            ->assertSee(__('postings.columns.source'))
            ->assertSee(__('postings.columns.captured'))
            ->assertSee(__('postings.columns.status'))
            ->sortTable('title')
            ->assertCanSeeTableRecords([$first, $last], inOrder: true)
            ->sortTable('title', 'desc')
            ->assertCanSeeTableRecords([$last, $first], inOrder: true);
    }

    /**
     * Actions that read as text are easy to miss. As buttons they are each
     * plainly a thing to press.
     */
    public function test_the_record_actions_are_buttons(): void
    {
        $this->signIn();

        $actions = $this->listTable()->getRecordActions();

        $this->assertNotEmpty($actions);

        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                $this->fail('A record action is not an action.');
            }

            $this->assertTrue($action->isButton(), $action->getName().' does not render as a button');
        }
    }

    /**
     * The table the list page assembles, which is where its columns, its
     * layout and its actions are answered from.
     */
    private function listTable(): Table
    {
        $page = Livewire::test(ListPostings::class)->instance();

        if (! $page instanceof ListPostings) {
            $this->fail('The list page is not the component under test.');
        }

        return $page->getTable();
    }

    public function test_filtering_by_status_narrows_the_list(): void
    {
        $this->signIn();

        $wanted = Posting::factory()->wanted()->create();
        $captured = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->filterTable('status', PostingStatus::Wanted->value)
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$captured]);
    }

    /**
     * A reader who wants discarded postings asks for them, and the list has
     * nothing to say about why they were not there to begin with.
     */
    public function test_selecting_discarded_shows_the_discarded_postings(): void
    {
        $this->signIn();

        $discarded = Posting::factory()->status(PostingStatus::Discarded)->create();
        $captured = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->filterTable('status', [PostingStatus::Discarded->value])
            ->assertCanSeeTableRecords([$discarded])
            ->assertCanNotSeeTableRecords([$captured]);
    }

    public function test_selecting_two_statuses_shows_both_and_nothing_else(): void
    {
        $this->signIn();

        $wanted = Posting::factory()->wanted()->create();
        $applied = Posting::factory()->status(PostingStatus::Applied)->create();
        $captured = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->filterTable('status', [PostingStatus::Wanted->value, PostingStatus::Applied->value])
            ->assertCanSeeTableRecords([$wanted, $applied])
            ->assertCanNotSeeTableRecords([$captured])
            ->assertCountTableRecords(2);
    }

    public function test_clearing_the_status_filter_shows_every_posting(): void
    {
        $this->signIn();

        $discarded = Posting::factory()->status(PostingStatus::Discarded)->create();
        $captured = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->filterTable('status', [])
            ->assertCanSeeTableRecords([$captured, $discarded])
            ->assertCountTableRecords(2);
    }

    /**
     * The default is a selection, so the indicator the filter puts on the list
     * carries its own control: what the list is doing is on screen and one
     * click undoes it.
     */
    public function test_removing_the_status_indicator_shows_every_posting(): void
    {
        $this->signIn();

        $discarded = Posting::factory()->status(PostingStatus::Discarded)->create();
        $captured = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->assertCanNotSeeTableRecords([$discarded])
            ->removeTableFilter('status')
            ->assertCanSeeTableRecords([$captured, $discarded])
            ->assertCountTableRecords(2);
    }

    /**
     * What the list is doing is on the screen: the selection it opened on is
     * named, so nothing has to explain why discarded postings are not there.
     */
    public function test_the_list_names_the_statuses_it_opened_on(): void
    {
        $this->signIn();

        Posting::factory()->create();

        $labels = [];

        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Discarded) {
                $labels[] = __('postings.statuses.'.$status->value);
            }
        }

        Livewire::test(ListPostings::class)
            ->assertSee(__('postings.columns.status').': '.collect($labels)->join(', ', ' & '));
    }

    public function test_the_status_filter_takes_more_than_one_status(): void
    {
        $this->signIn();

        Livewire::test(ListPostings::class)
            ->assertTableFilterExists('status', fn (SelectFilter $filter): bool => $filter->isMultiple());
    }

    /**
     * Derived from the enum, so a status added to the machine is a status the
     * filter offers without this being edited.
     */
    public function test_the_status_filter_offers_every_case_of_the_enum(): void
    {
        $this->signIn();

        $options = [];

        foreach (PostingStatus::cases() as $status) {
            $options[$status->value] = __('postings.statuses.'.$status->value);
        }

        Livewire::test(ListPostings::class)
            ->assertTableFilterExists(
                'status',
                fn (SelectFilter $filter): bool => $filter->getOptions() === $options,
            );
    }

    /**
     * And the opening selection is derived from it too: a status added later
     * is one the list opens on.
     */
    public function test_the_status_filter_opens_on_every_case_of_the_enum_but_discarded(): void
    {
        $this->signIn();

        $expected = [];

        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Discarded) {
                $expected[] = $status->value;
            }
        }

        Livewire::test(ListPostings::class)
            ->assertTableFilterExists(
                'status',
                fn (SelectFilter $filter): bool => $filter->getDefaultState() === $expected,
            );
    }

    public function test_filtering_by_source_narrows_the_list(): void
    {
        $this->signIn();

        $here = Posting::factory()->create(['source_site' => 'one-board.test']);
        $elsewhere = Posting::factory()->create(['source_site' => 'other-board.test']);

        Livewire::test(ListPostings::class)
            ->filterTable('source_site', 'one-board.test')
            ->assertCanSeeTableRecords([$here])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    public function test_the_source_filter_takes_more_than_one_site(): void
    {
        $this->signIn();

        $here = Posting::factory()->create(['source_site' => 'one-board.test']);
        $alsoHere = Posting::factory()->create(['source_site' => 'other-board.test']);
        $elsewhere = Posting::factory()->create(['source_site' => 'third-board.test']);

        Livewire::test(ListPostings::class)
            ->assertTableFilterExists('source_site', fn (SelectFilter $filter): bool => $filter->isMultiple())
            ->filterTable('source_site', ['one-board.test', 'other-board.test'])
            ->assertCanSeeTableRecords([$here, $alsoHere])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    /**
     * The sites the owner has actually captured from, rather than a list of
     * boards held somewhere in configuration.
     */
    public function test_the_source_filter_lists_only_sites_that_have_been_captured_from(): void
    {
        $this->signIn();

        Posting::factory()->create(['source_site' => 'one-board.test']);
        Posting::factory()->create(['source_site' => 'one-board.test']);

        Livewire::test(ListPostings::class)
            ->assertTableFilterExists(
                'source_site',
                fn (SelectFilter $filter): bool => $filter->getOptions() === ['one-board.test' => 'one-board.test'],
            );
    }

    public function test_the_source_filter_has_no_default(): void
    {
        $this->signIn();

        Posting::factory()->create(['source_site' => 'one-board.test']);
        $elsewhere = Posting::factory()->create(['source_site' => 'other-board.test']);

        Livewire::test(ListPostings::class)
            ->assertTableFilterExists(
                'source_site',
                fn (SelectFilter $filter): bool => blank($filter->getDefaultState()),
            )
            ->assertCanSeeTableRecords([$elsewhere]);
    }

    public function test_marking_a_posting_wanted_is_offered_on_a_captured_posting(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->assertTableActionVisible('want', $posting)
            ->callTableAction('want', $posting);

        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }

    /**
     * Marking a posting as wanted is reversible by discarding and costs
     * nothing until the sweep runs, so it acts on the click.
     */
    public function test_marking_a_posting_wanted_does_not_ask_first(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create();

        Livewire::test(ListPostings::class)
            ->callTableAction('want', $posting)
            ->assertActionNotMounted();

        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }

    #[DataProvider('statusesThatCannotBeMarked')]
    public function test_marking_a_posting_wanted_is_offered_on_nothing_else(PostingStatus $status): void
    {
        $this->signIn();

        $posting = Posting::factory()->status($status)->create();

        Livewire::test(ListPostings::class)->assertTableActionHidden('want', $posting);
    }

    /**
     * @return iterable<string, array{PostingStatus}>
     */
    public static function statusesThatCannotBeMarked(): iterable
    {
        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Captured) {
                yield $status->value => [$status];
            }
        }
    }

    #[DataProvider('statusesThatCanBeDiscarded')]
    public function test_discarding_is_offered_on_anything_not_already_discarded(PostingStatus $status): void
    {
        $this->signIn();

        $posting = Posting::factory()->status($status)->create();

        Livewire::test(ListPostings::class)
            ->assertTableActionVisible('discard', $posting)
            ->callTableAction('discard', $posting);

        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);
    }

    /**
     * @return iterable<string, array{PostingStatus}>
     */
    public static function statusesThatCanBeDiscarded(): iterable
    {
        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Discarded) {
                yield $status->value => [$status];
            }
        }
    }

    /**
     * Nothing moves a posting off `discarded`, so the one action that cannot
     * be undone from here asks before it acts.
     */
    public function test_discarding_asks_first_and_leaves_the_posting_alone_until_it_is_confirmed(): void
    {
        $this->signIn();

        $posting = Posting::factory()->create();

        $list = Livewire::test(ListPostings::class)
            ->mountTableAction('discard', $posting)
            ->assertActionMounted(TestAction::make('discard')->table($posting));

        $this->assertSame(PostingStatus::Captured, $posting->refresh()->status);

        $list->callMountedAction();

        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);
    }

    public function test_the_confirmation_is_a_heading_and_a_button(): void
    {
        $this->signIn();

        $list = Livewire::test(ListPostings::class)
            ->mountTableAction('discard', Posting::factory()->create());

        $list->assertMountedActionModalSee([
            __('postings.discard_confirmation.heading'),
            __('postings.discard_confirmation.confirm'),
        ]);

        // The row behind the modal already says which job it is, so there is
        // nothing for a paragraph to add. Filament's own confirmation sentence
        // is what would arrive if nobody said otherwise.
        $list->assertMountedActionModalDontSee(__('filament-actions::modal.confirmation'));
    }

    public function test_discarding_is_not_offered_on_a_discarded_posting(): void
    {
        $this->signIn();

        $posting = Posting::factory()->status(PostingStatus::Discarded)->create();

        Livewire::test(ListPostings::class)->assertTableActionHidden('discard', $posting);
    }

    public function test_the_download_hands_back_the_current_revisions_pdf(): void
    {
        $this->signIn();

        $posting = $this->readyToDownload(['company' => 'Northwind Logistics']);

        Livewire::test(ListPostings::class)
            ->assertTableActionVisible('download', $posting)
            ->callTableAction('download', $posting)
            ->assertFileDownloaded(
                __('postings.pdf_filename_for_company', ['company' => 'northwind-logistics']),
                '%PDF-1.7 the current one',
                'application/pdf',
            );
    }

    /**
     * The filename reaches an employer, and a posting's id means nothing to
     * anybody outside this database.
     */
    public function test_the_download_is_named_for_the_company(): void
    {
        $this->signIn();

        $posting = $this->readyToDownload(['company' => 'Ørsted  Rail & Freight, Ltd.']);

        Livewire::test(ListPostings::class)
            ->callTableAction('download', $posting)
            ->assertFileDownloaded(
                __('postings.pdf_filename_for_company', ['company' => 'orsted-rail-freight-ltd']),
            );
    }

    public function test_the_download_falls_back_to_the_posting_where_the_capture_found_no_company(): void
    {
        $this->signIn();

        $posting = $this->readyToDownload(['company' => null]);

        Livewire::test(ListPostings::class)
            ->callTableAction('download', $posting)
            ->assertFileDownloaded(
                __('postings.pdf_filename', ['posting' => $posting->id]),
            );
    }

    public function test_the_download_falls_back_where_the_company_slugs_to_nothing(): void
    {
        $this->signIn();

        $posting = $this->readyToDownload(['company' => '!!! ---']);

        Livewire::test(ListPostings::class)
            ->callTableAction('download', $posting)
            ->assertFileDownloaded(
                __('postings.pdf_filename', ['posting' => $posting->id]),
            );
    }

    public function test_the_download_is_withheld_where_the_current_revision_has_no_pdf(): void
    {
        $this->signIn();

        $posting = Posting::factory()->wanted()->create();
        $revision = Revision::factory()->for($posting)->create();

        $posting->update(['current_revision_id' => $revision->id]);

        Livewire::test(ListPostings::class)->assertTableActionHidden('download', $posting);
    }

    public function test_the_download_is_withheld_where_there_is_no_revision_at_all(): void
    {
        $this->signIn();

        Livewire::test(ListPostings::class)
            ->assertTableActionHidden('download', Posting::factory()->create());
    }

    /**
     * A posting whose current revision has a PDF on the disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function readyToDownload(array $attributes = []): Posting
    {
        $posting = Posting::factory()->status(PostingStatus::Ready)->create($attributes);
        $revision = $this->rendered($posting, '%PDF-1.7 the current one');

        $posting->update(['current_revision_id' => $revision->id]);

        return $posting;
    }

    /**
     * A revision whose own PDF is on the disk, which is what the download
     * reads: the path is derived from the revision's ID, so a posting has one
     * PDF per revision and the current one decides which.
     */
    private function rendered(Posting $posting, string $pdf): Revision
    {
        $revision = Revision::factory()
            ->for($posting)
            ->status(RevisionStatus::Validated)
            ->create(['validation_json' => []]);

        $path = 'revisions/'.$revision->id.'.pdf';

        Storage::disk('pdfs')->put($path, $pdf);

        // The move the render itself makes: into `ready` carrying the path.
        // A revision's PDF is named from its ID, so the path is only knowable
        // once the row exists.
        $revision->update(['pdf_path' => $path, 'status' => RevisionStatus::Ready]);

        return $revision;
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }
}
