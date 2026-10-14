<?php

namespace Tests\Feature\Generation;

use App\Generation\PromptVersion;
use App\Generation\QueuePostings;
use App\MasterCv\MasterCvIsNotUsable;
use App\Models\MasterCv;
use App\Models\MasterCvVersion;
use App\Models\Posting;
use App\Models\Revision;
use App\Postings\PostingStatus;
use App\Revisions\RevisionSource;
use App\Revisions\RevisionStatus;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class QueuePostingsTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_a_wanted_posting_gains_one_pending_revision_at_the_current_cv_version(): void
    {
        $posting = Posting::factory()->wanted()->create();

        $outcome = $this->queue()->handle();

        $version = MasterCvVersion::query()->sole();

        $this->assertSame(1, $outcome->postings);
        $this->assertSame($version->id, $outcome->masterCvVersionId);
        $this->assertSame($this->parseFixture('master-cv/minimal.md')->hash(), $version->hash);

        $revision = Revision::query()->sole();

        $this->assertSame($posting->id, $revision->posting_id);
        $this->assertSame($version->id, $revision->master_cv_version_id);
        $this->assertSame(RevisionSource::Generated, $revision->source);
        $this->assertSame(RevisionStatus::Pending, $revision->status);
        $this->assertNull($revision->parent_revision_id);
        $this->assertSame(PromptVersion::CURRENT, $revision->prompt_version);
        $this->assertSame(0, $revision->attempts);

        $posting->refresh();

        $this->assertSame(PostingStatus::Queued, $posting->status);
        $this->assertSame($revision->id, $posting->current_revision_id);
        $this->assertNull($posting->applied_revision_id);
    }

    /**
     * The model and the prompt version are written again when the request is
     * built and when the response is stored. A pending revision carries what
     * would generate it now because neither column may be null.
     */
    public function test_a_pending_revision_names_the_configured_generation_model(): void
    {
        config(['cv.llm.models.generation' => 'claude-opus-5']);

        Posting::factory()->wanted()->create();

        $this->queue()->handle();

        $this->assertSame('claude-opus-5', Revision::query()->sole()->model);
    }

    public function test_a_run_that_queues_five_postings_parses_the_cv_once(): void
    {
        $log = Log::spy();

        Posting::factory()->wanted()->count(5)->create();

        $outcome = $this->queue()->handle();

        $this->assertSame(5, $outcome->postings);
        $this->assertDatabaseCount('revisions', 5);

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message): bool => $message === 'master_cv.parsed',
        )->once();
    }

    public function test_it_queues_the_oldest_posting_first(): void
    {
        $postings = Posting::factory()->wanted()->count(3)->create();

        $this->queue()->handle();

        $this->assertSame(
            $postings->pluck('id')->all(),
            Revision::query()->orderBy('id')->pluck('posting_id')->all(),
        );
    }

    /**
     * Nothing is lost. The postings stay where they are, and the first run
     * after the CV is fixed picks up everything that was waiting.
     */
    public function test_a_master_cv_that_fails_lint_leaves_every_posting_wanted(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        Posting::factory()->wanted()->count(3)->create();

        try {
            $this->queue()->handle();
            $this->fail('A master CV that fails lint must block the queue.');
        } catch (MasterCvIsNotUsable $exception) {
            $this->assertSame(1, $exception->problems);
        }

        $this->assertDatabaseCount('revisions', 0);
        $this->assertDatabaseCount('master_cv_versions', 0);
        $this->assertSame(3, Posting::query()->where('status', PostingStatus::Wanted)->count());
        $this->assertSame(0, Posting::query()->whereNotNull('current_revision_id')->count());
    }

    public function test_a_blocked_queue_warns_with_the_counts_and_never_the_findings(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $log = Log::spy();

        Posting::factory()->wanted()->count(3)->create();

        try {
            $this->queue()->handle();
        } catch (MasterCvIsNotUsable) {
            // The warning is what this test is about.
        }

        $log->shouldHaveReceived('log')->withArgs(
            function (string $level, string $message, array $context): bool {
                return $level === 'warning'
                    && $message === 'queue.blocked'
                    && $context['event'] === 'queue.blocked'
                    && $context['postings_waiting'] === 3
                    && $context['problems'] === 1;
            }
        )->once();
    }

    /**
     * A fresh installation. Nothing is stored, so `queue()` is not used here:
     * its default would store one.
     */
    public function test_no_master_cv_at_all_blocks_the_queue_with_no_count_of_problems(): void
    {
        $log = Log::spy();

        Posting::factory()->wanted()->create();

        try {
            app(QueuePostings::class)->handle();
            $this->fail('An installation with no master CV must block the queue.');
        } catch (MasterCvIsNotUsable $exception) {
            $this->assertNull($exception->problems);
        }

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'queue.blocked'
                && $context['problems'] === null,
        )->once();
    }

    public function test_a_posting_that_already_has_a_revision_is_not_given_another(): void
    {
        $posting = Posting::factory()->wanted()->create();
        $revision = Revision::factory()->for($posting)->create();

        $outcome = $this->queue()->handle();

        $this->assertSame(0, $outcome->postings);
        $this->assertDatabaseCount('revisions', 1);
        $this->assertSame($revision->id, Revision::query()->sole()->id);
        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }

    public function test_re_running_it_queues_nothing_a_second_time(): void
    {
        Posting::factory()->wanted()->count(2)->create();

        $this->queue()->handle();
        $outcome = $this->queue()->handle();

        $this->assertSame(0, $outcome->postings);
        $this->assertDatabaseCount('revisions', 2);
    }

    /**
     * The gate. Capture is free and speculative, and generation is not, so a
     * posting nobody has asked for is left exactly where it is — and costs
     * nothing however many sweeps run over it.
     */
    public function test_a_captured_posting_is_left_alone(): void
    {
        $posting = Posting::factory()->create();

        $outcome = $this->queue()->handle();
        $this->queue()->handle();

        $this->assertSame(0, $outcome->postings);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Captured, $posting->refresh()->status);
        $this->assertNull($posting->current_revision_id);
    }

    public function test_it_queues_a_wanted_posting_and_ignores_a_captured_one(): void
    {
        $captured = Posting::factory()->create();
        $wanted = Posting::factory()->wanted()->create();

        $outcome = $this->queue()->handle();

        $this->assertSame(1, $outcome->postings);
        $this->assertSame($wanted->id, Revision::query()->sole()->posting_id);
        $this->assertSame(PostingStatus::Queued, $wanted->refresh()->status);
        $this->assertSame(PostingStatus::Captured, $captured->refresh()->status);
    }

    public function test_a_discarded_posting_is_never_queued(): void
    {
        Posting::factory()->status(PostingStatus::Discarded)->create();

        $outcome = $this->queue()->handle();

        $this->assertSame(0, $outcome->postings);
        $this->assertDatabaseCount('revisions', 0);
    }

    /**
     * The other half of the gate. The page's own text is not a job
     * description, so a posting there is nothing to tailor against yet waits
     * rather than being generated from the navigation and the sidebar.
     */
    public function test_a_wanted_posting_with_no_description_is_not_queued(): void
    {
        $posting = Posting::factory()->wanted()->awaitingExtraction()->create();

        $outcome = $this->queue()->handle();
        $this->queue()->handle();

        $this->assertSame(0, $outcome->postings);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
        $this->assertNull($posting->current_revision_id);
    }

    /**
     * It waits rather than failing, so nothing has to be retried by hand: the
     * posting stays at `wanted` and the first sweep after a description
     * arrives picks it up.
     */
    public function test_it_queues_the_posting_on_the_first_sweep_after_extraction_fills_it(): void
    {
        $posting = Posting::factory()->wanted()->awaitingExtraction()->create();

        $this->assertSame(0, $this->queue()->handle()->postings);

        $posting->fill([
            'description' => 'We need someone who has run a dispatch platform.',
            'extracted_at' => now(),
        ])->save();

        $outcome = $this->queue()->handle();

        $this->assertSame(1, $outcome->postings);
        $this->assertSame($posting->id, Revision::query()->sole()->posting_id);
        $this->assertSame(PostingStatus::Queued, $posting->refresh()->status);
    }

    public function test_an_unchanged_cv_reuses_the_version_already_stored(): void
    {
        Posting::factory()->wanted()->create();
        $this->queue()->handle();

        Posting::factory()->wanted()->create();
        $this->queue()->handle();

        $this->assertDatabaseCount('master_cv_versions', 1);
        $this->assertDatabaseCount('revisions', 2);
        $this->assertSame(1, Revision::query()->distinct()->count('master_cv_version_id'));
    }

    /**
     * A run with no work does not read the CV, so a CV that is mid-edit is not
     * an alarm until there is something waiting on it.
     */
    public function test_nothing_waiting_reads_nothing_and_says_nothing(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $log = Log::spy();

        $outcome = $this->queue()->handle();

        $this->assertSame(0, $outcome->postings);
        $this->assertNull($outcome->masterCvVersionId);
        $this->assertDatabaseCount('master_cv_versions', 0);

        $log->shouldNotHaveReceived('log');
    }

    public function test_it_logs_the_posting_it_queued(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->wanted()->create();

        $this->queue()->handle();

        $revision = Revision::query()->sole();

        $log->shouldHaveReceived('log')->withArgs(
            function (string $level, string $message, array $context) use ($posting, $revision): bool {
                return $message === 'posting.queued'
                    && $context['event'] === 'posting.queued'
                    && $context['posting_id'] === $posting->id
                    && $context['revision_id'] === $revision->id
                    && $context['master_cv_version_id'] === $revision->master_cv_version_id;
            }
        )->once();
    }

    /**
     * Two runs at once cannot both give a posting a revision. The claim is a
     * conditional update, so the rival run here — which moves the posting off
     * `captured` in the moment between this run reading it and claiming it —
     * leaves the claim matching no row.
     */
    public function test_a_concurrent_run_cannot_create_a_second_revision(): void
    {
        $posting = Posting::factory()->wanted()->create();

        /** @var Connection $connection */
        $connection = DB::connection();

        $rivalHasRun = false;

        $connection->beforeExecuting(function (string $query) use (&$rivalHasRun, $posting): void {
            if ($rivalHasRun || ! str_contains($query, 'update "postings"')) {
                return;
            }

            // Set before the rival's own update, which would otherwise arrive
            // back here and recurse.
            $rivalHasRun = true;

            Posting::query()->whereKey($posting->id)->update(['status' => PostingStatus::Queued]);
        });

        $outcome = $this->queue()->handle();

        $this->assertTrue($rivalHasRun);
        $this->assertSame(0, $outcome->postings);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertNull($posting->refresh()->current_revision_id);
    }

    /**
     * The CV a case runs against is stored before the run, because that is
     * where the run reads it from. The smallest CV that passes lint is the
     * default, and a case wanting another stores it itself.
     */
    private function queue(): QueuePostings
    {
        if (MasterCv::query()->doesntExist()) {
            $this->storeMasterCv('master-cv/minimal.md');
        }

        return app(QueuePostings::class);
    }
}
