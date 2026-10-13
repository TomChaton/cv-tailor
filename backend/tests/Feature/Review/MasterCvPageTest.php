<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\MasterCv;
use App\MasterCv\Ids\AssignMasterCvIds;
use App\MasterCv\InspectMasterCv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\TemporaryFiles;
use Tests\TestCase;

class MasterCvPageTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use TemporaryFiles;

    public function test_it_is_not_reachable_without_signing_in(): void
    {
        $this->get(MasterCv::getUrl())->assertRedirect('/review/login');
    }

    public function test_it_is_in_the_navigation(): void
    {
        $this->signIn();

        $this->get(MasterCv::getUrl())
            ->assertSuccessful()
            ->assertSee(__('master-cv.title'))
            ->assertSee(MasterCv::getUrl());
    }

    public function test_it_reports_the_path_the_version_and_the_counts(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath()]);

        $counts = $this->parseFixture()->counts();

        $this->get(MasterCv::getUrl())
            ->assertSuccessful()
            ->assertSee(__('master-cv.path'))
            ->assertSee($this->fixturePath())
            ->assertSee(__('master-cv.modified'))
            ->assertSee(__('master-cv.version'))
            ->assertSee($this->parseFixture()->hash())
            ->assertSee(__('master-cv.counts.roles'))
            ->assertSee(__('master-cv.counts.sub_bullets'))
            ->assertSeeInOrder([__('master-cv.counts.skills'), (string) $counts['skills']]);
    }

    public function test_a_cv_that_passes_lint_shows_no_problems(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/minimal.md')]);

        $this->get(MasterCv::getUrl())
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
            'a stack entry missing from the index' => ['broken-tech-stack-not-in-index.md', 'tech_stack_coverage', 'line 46'],
        ];
    }

    #[DataProvider('brokenFixtures')]
    public function test_a_problem_is_shown_under_its_check_with_its_line(
        string $fixture,
        string $check,
        string $where,
    ): void {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/'.$fixture)]);

        $report = app(InspectMasterCv::class)->handle();
        $problem = $report->problemsByCheck()[$check][0];

        $response = $this->get(MasterCv::getUrl())
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
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/broken-missing-id.md')]);

        $this->get(MasterCv::getUrl())
            ->assertSuccessful()
            ->assertSee('This achievement has no ID.')
            ->assertSee(__('master-cv.actions.add_ids'))
            ->assertDontSee('artisan');
    }

    public function test_notes_are_shown_apart_from_the_problems(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath()]);

        $notes = app(InspectMasterCv::class)->handle()->notes();

        $this->get(MasterCv::getUrl())
            ->assertSuccessful()
            ->assertSeeInOrder([
                __('master-cv.problems'),
                __('master-cv.passes'),
                __('master-cv.notes'),
                __('master-cv.checks.block_labels'),
                $notes[0]->message,
            ]);
    }

    public function test_a_path_it_cannot_read_is_reported_as_that(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->temporaryPath('nothing-here.md')]);

        $this->get(MasterCv::getUrl())
            ->assertSuccessful()
            ->assertSee($this->temporaryPath('nothing-here.md'))
            ->assertSee(__('master-cv.unreadable.not_found'))
            ->assertDontSee(__('master-cv.problems'))
            ->assertDontSee(__('master-cv.passes'));
    }

    public function test_a_directory_at_the_path_is_reported_as_one(): void
    {
        $this->signIn();
        mkdir($this->temporaryPath('master-cv.md'));
        config(['cv.master_cv.path' => $this->temporaryPath('master-cv.md')]);

        $this->get(MasterCv::getUrl())
            ->assertSuccessful()
            ->assertSee(__('master-cv.unreadable.directory'));
    }

    public function test_the_download_is_withheld_when_nothing_is_missing_an_id(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath()]);

        Livewire::test(MasterCv::class)
            ->assertActionVisible('recheck')
            ->assertActionHidden('addIds');
    }

    public function test_the_download_is_offered_when_an_id_is_missing(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/broken-missing-id.md')]);

        Livewire::test(MasterCv::class)->assertActionVisible('addIds');
    }

    public function test_the_download_is_what_cv_id_produces_for_the_same_file(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/broken-missing-id.md')]);

        $expected = (new AssignMasterCvIds($this->parser()))
            ->handle($this->fixture('master-cv/broken-missing-id.md'))
            ->content;

        Livewire::test(MasterCv::class)
            ->callAction('addIds')
            ->assertFileDownloaded(__('master-cv.download_filename'), $expected);
    }

    public function test_a_re_check_reports_the_file_as_it_is_now(): void
    {
        $this->signIn();
        $path = $this->temporaryPath('master-cv.md');
        copy($this->fixturePath('master-cv/minimal.md'), $path);
        config(['cv.master_cv.path' => $path]);

        $page = Livewire::test(MasterCv::class)
            ->assertSee(__('master-cv.passes'))
            ->assertActionHidden('addIds');

        file_put_contents($path, str_replace(
            ' {#wrenbury-freight.2}',
            '',
            (string) file_get_contents($path),
        ));

        $page->call('recheck')
            ->assertDontSee(__('master-cv.passes'))
            ->assertSee(__('master-cv.checks.ids'))
            ->assertActionVisible('addIds');
    }

    public function test_a_re_check_of_an_unchanged_file_reuses_the_version_already_stored(): void
    {
        $this->signIn();
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/minimal.md')]);

        Livewire::test(MasterCv::class)
            ->call('recheck')
            ->call('recheck');

        $this->assertDatabaseCount('master_cv_versions', 1);
    }

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }
}
