<?php

namespace Tests\Feature\Console;

use App\Batching\LlmBatchState;
use App\Batching\SubmitterHalt;
use App\Models\LlmBatch;
use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Observability\HeartbeatStore;
use App\Owner\IssueExtensionToken;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CvStatusCommandTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_says_plainly_when_a_process_has_never_reported(): void
    {
        $this->artisan('cv:status')
            ->expectsOutputToContain('Processes')
            ->expectsOutputToContain('never reported')
            ->assertSuccessful();
    }

    public function test_it_reports_each_process_and_the_age_of_its_heartbeat(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

        $store = app(HeartbeatStore::class);
        $store->record('worker', CarbonImmutable::now());
        $store->record('scheduler', CarbonImmutable::now()->subMinutes(5));

        $this->artisan('cv:status')
            ->doesntExpectOutputToContain('never reported')
            ->expectsOutputToContain('5 minutes ago')
            ->assertSuccessful();
    }

    /**
     * A review UI nobody has an account for and an API nothing holds a token
     * for look exactly like a stack that is working, so both say so here.
     */
    public function test_it_reports_a_stack_nobody_can_get_into(): void
    {
        $this->artisan('cv:status')
            ->expectsOutputToContain('Access')
            ->expectsOutputToContain('none: run php artisan cv:owner')
            ->expectsOutputToContain('none: run php artisan cv:token')
            ->assertSuccessful();
    }

    public function test_it_reports_an_owner_and_a_token_once_they_are_there(): void
    {
        app(IssueExtensionToken::class)->handle(User::factory()->create(), 'extension');

        $this->artisan('cv:status')
            ->expectsOutputToContain('Access')
            ->doesntExpectOutputToContain('none: run php artisan cv:owner')
            ->doesntExpectOutputToContain('none: run php artisan cv:token')
            ->assertSuccessful();
    }

    public function test_it_reports_the_queue(): void
    {
        $this->artisan('cv:status')
            ->expectsOutputToContain('Queue')
            ->expectsOutputToContain('pending jobs')
            ->expectsOutputToContain('failed jobs')
            ->assertSuccessful();
    }

    /**
     * A fresh installation has no CV. That is the normal first state now, not
     * an error, so it reads as one - and it names the two ways out.
     */
    public function test_it_reports_an_installation_with_no_master_cv(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('Master CV', $output);
        $this->assertStringContainsString('stored', $output);
        $this->assertStringContainsString('cv:import', $output);
        $this->assertStringNotContainsString('saved', $output);
    }

    public function test_it_reports_when_the_master_cv_was_saved_and_what_it_parses_to(): void
    {
        $this->storeMasterCv();

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('Master CV', $output);
        $this->assertStringContainsString('stored', $output);
        $this->assertStringContainsString('saved', $output);
        $this->assertStringContainsString('version', $output);
        $this->assertStringContainsString($this->parseFixture()->hash(), $output);
        $this->assertStringContainsString('passing', $output);
    }

    public function test_it_reports_a_master_cv_that_would_be_refused(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $this->assertStringContainsString('1 problem: run php artisan cv:lint', Artisan::output());
    }

    /**
     * A precondition the owner can see and fix, rather than a stack trace from
     * every scheduled run.
     */
    public function test_it_reports_whether_the_api_key_is_configured(): void
    {
        config(['cv.llm.api_key' => '']);

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('api key', $output);
        $this->assertStringContainsString('not set: put ANTHROPIC_API_KEY in .env', $output);
    }

    public function test_it_says_the_api_key_is_set_without_printing_any_of_it(): void
    {
        config(['cv.llm.api_key' => 'sk-ant-secret-value']);

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('api key', $output);
        $this->assertStringNotContainsString('secret', $output);
    }

    /**
     * An empty state has to be visible rather than absent: a status missing
     * from the report cannot be told apart from one that has been renamed.
     */
    public function test_it_reports_every_posting_and_revision_status_on_an_empty_database(): void
    {
        $command = $this->artisan('cv:status')
            ->expectsOutputToContain('Postings')
            ->expectsOutputToContain('Revisions');

        // `ready` is both a posting status and a revision status, and one
        // expectation per distinct word is all this asserts.
        $statuses = array_unique([...PostingStatus::values(), ...RevisionStatus::values()]);

        foreach ($statuses as $status) {
            $command->expectsOutputToContain($status);
        }

        $command->assertSuccessful();
    }

    public function test_it_reports_every_batch_state_and_a_running_submitter(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('Batches', $output);
        $this->assertStringContainsString('submitter', $output);
        $this->assertStringContainsString('running', $output);
        $this->assertStringContainsString('oldest open', $output);
        $this->assertStringContainsString('none', $output);

        // An empty state has to be visible rather than absent.
        foreach (LlmBatchState::values() as $state) {
            $this->assertStringContainsString($state, $output);
        }
    }

    /**
     * A stopped submitter has to be the first thing a reader sees, rather than
     * something inferred from a pile of pending revisions that are not moving.
     */
    public function test_it_reports_a_halted_submitter_and_the_way_back(): void
    {
        $halt = $this->app->make(SubmitterHalt::class);
        $halt->recordFullyInvalid(3);
        $halt->recordFullyInvalid(4);

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('halted: run php artisan cv:batch-resume', $output);
        $this->assertStringContainsString('batches 3, 4', $output);
    }

    /**
     * The section's counts and its "oldest open" line read the same table, so
     * a report that finds a batch on one line and none on the others is
     * contradicting itself.
     */
    public function test_it_counts_the_batches_the_table_holds(): void
    {
        LlmBatch::factory()->count(2)->create();
        LlmBatch::factory()->inState(LlmBatchState::Collected)->create();

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/submitted[\s.]+2/', $output);
        $this->assertMatchesRegularExpression('/collected[\s.]+1/', $output);
        $this->assertMatchesRegularExpression('/expired[\s.]+0/', $output);
    }

    public function test_it_calls_out_the_batch_that_has_been_open_longest(): void
    {
        $oldest = LlmBatch::factory()->create(['submitted_at' => CarbonImmutable::now()->subHours(3)]);
        LlmBatch::factory()->create();
        LlmBatch::factory()->inState(LlmBatchState::Collected)->create();

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $this->assertStringContainsString(
            'batch '.$oldest->id.', submitted 3 hours ago',
            Artisan::output(),
        );
    }

    /**
     * The count generation's sweep waits on, which is a job description and
     * not `extracted_at`: a posting extraction answered without one is not
     * queued either, so a count that missed it would read zero while nothing
     * generated. Extraction is a queued job per capture, so a worker that has
     * stopped is a count that does not come down.
     */
    public function test_it_reports_how_many_postings_have_no_job_description(): void
    {
        Posting::factory()->count(2)->awaitingExtraction()->create();
        Posting::factory()->create(['description' => null]);
        Posting::factory()->create();

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:status'));

        $output = Artisan::output();

        $this->assertStringContainsString('Extraction', $output);
        $this->assertMatchesRegularExpression('/awaiting a job description[\s.]+3/', $output);
        $this->assertMatchesRegularExpression('/extraction has not run[\s.]+2/', $output);
        $this->assertMatchesRegularExpression('/extraction found none[\s.]+1/', $output);
        $this->assertMatchesRegularExpression('/with a job description[\s.]+1/', $output);
    }

    public function test_the_new_sections_leave_the_ones_above_them_alone(): void
    {
        Posting::factory()->create();
        Revision::factory()->create();
        LlmBatch::factory()->create();

        $this->artisan('cv:status')
            ->expectsOutputToContain('Processes')
            ->expectsOutputToContain('Queue')
            ->expectsOutputToContain('Master CV')
            ->expectsOutputToContain('LLM')
            ->expectsOutputToContain('Postings')
            ->expectsOutputToContain('Extraction')
            ->expectsOutputToContain('Revisions')
            ->expectsOutputToContain('Batches')
            ->assertSuccessful();
    }
}
