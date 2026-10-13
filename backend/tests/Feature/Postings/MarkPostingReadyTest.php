<?php

namespace Tests\Feature\Postings;

use App\Models\Posting;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Postings\MarkPostingReady;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MarkPostingReadyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queued_posting_follows_its_current_revision_to_ready(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Queued);

        $this->assertTrue($this->mark()->handle($revision));
        $this->assertSame(PostingStatus::Ready, $posting->refresh()->status);
    }

    /**
     * `reviewed` and `applied` are things the owner did. A render that lands
     * afterwards must not undo them.
     */
    public function test_a_reviewed_posting_is_left_alone(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Reviewed);

        $this->assertFalse($this->mark()->handle($revision));
        $this->assertSame(PostingStatus::Reviewed, $posting->refresh()->status);
    }

    public function test_an_applied_posting_is_left_alone(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Applied);

        $this->assertFalse($this->mark()->handle($revision));
        $this->assertSame(PostingStatus::Applied, $posting->refresh()->status);
    }

    public function test_a_discarded_posting_is_never_moved(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Discarded);

        $this->assertFalse($this->mark()->handle($revision));
        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);
    }

    /**
     * A sibling that rendered late says nothing about where the posting has
     * got to: the owner is looking at a different revision.
     */
    public function test_a_revision_that_is_not_the_current_one_moves_nothing(): void
    {
        [$posting, $current] = $this->posting(PostingStatus::Queued);

        $sibling = Revision::factory()
            ->childOf($current)
            ->status(RevisionStatus::Ready)
            ->create();

        $this->assertFalse($this->mark()->handle($sibling));
        $this->assertSame(PostingStatus::Queued, $posting->refresh()->status);
    }

    public function test_a_revision_that_is_not_ready_moves_nothing(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Queued, RevisionStatus::Validated);

        $this->assertFalse($this->mark()->handle($revision));
        $this->assertSame(PostingStatus::Queued, $posting->refresh()->status);
    }

    /**
     * A duplicate dispatch is the ordinary consequence of a retried job, so
     * nothing may depend on the transition happening exactly once.
     */
    public function test_running_it_twice_leaves_one_ready_posting_and_one_event(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Queued);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('posting.ready', Mockery::any());

        $this->instance(EventLogger::class, $logger);

        $mark = $this->mark();

        $this->assertTrue($mark->handle($revision));
        $this->assertFalse($mark->handle($revision));
        $this->assertSame(PostingStatus::Ready, $posting->refresh()->status);
        $this->assertSame(
            1,
            Posting::query()->where('status', PostingStatus::Ready)->count(),
        );
    }

    public function test_it_logs_the_posting_and_the_revision(): void
    {
        [$posting, $revision] = $this->posting(PostingStatus::Queued);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('posting.ready', Mockery::on(function (array $context) use ($posting, $revision): bool {
                $this->assertSame($posting->id, $context['posting_id']);
                $this->assertSame($revision->id, $context['revision_id']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->mark()->handle($revision);
    }

    /**
     * @return array{Posting, Revision}
     */
    private function posting(
        PostingStatus $status,
        RevisionStatus $revisionStatus = RevisionStatus::Ready,
    ): array {
        $posting = Posting::factory()->status($status)->create();

        $revision = Revision::factory()
            ->status($revisionStatus)
            ->create(['posting_id' => $posting->id]);

        $posting->fill(['current_revision_id' => $revision->id])->save();

        return [$posting, $revision];
    }

    private function mark(): MarkPostingReady
    {
        return $this->app->make(MarkPostingReady::class);
    }
}
