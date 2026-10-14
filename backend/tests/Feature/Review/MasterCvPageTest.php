<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\MasterCv as MasterCvPage;
use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\InspectMasterCv;
use App\Models\MasterCv;
use App\Models\User;
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

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }
}
