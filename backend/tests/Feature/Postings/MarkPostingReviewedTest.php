<?php

namespace Tests\Feature\Postings;

use App\Models\Posting;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Postings\MarkPostingReviewed;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarkPostingReviewedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ready_posting_becomes_reviewed(): void
    {
        $posting = $this->posting(PostingStatus::Ready);

        $this->assertTrue($this->mark()->handle($posting));
        $this->assertSame(PostingStatus::Reviewed, $posting->refresh()->status);
    }

    /**
     * Nothing moves a posting backwards, and `reviewed` is reachable from the
     * one state a CV becomes readable in.
     *
     * @return list<array{PostingStatus}>
     */
    public static function statusesThatDoNotMove(): array
    {
        return [
            [PostingStatus::Captured],
            [PostingStatus::Wanted],
            [PostingStatus::Queued],
            [PostingStatus::Reviewed],
            [PostingStatus::Applied],
            [PostingStatus::Discarded],
        ];
    }

    #[DataProvider('statusesThatDoNotMove')]
    public function test_nothing_but_a_ready_posting_moves(PostingStatus $status): void
    {
        $posting = $this->posting($status);

        $this->assertFalse($this->mark()->handle($posting));
        $this->assertSame($status, $posting->refresh()->status);
    }

    public function test_marking_it_twice_writes_once_and_says_so_once(): void
    {
        $posting = $this->posting(PostingStatus::Ready);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('posting.reviewed', Mockery::any());

        $this->instance(EventLogger::class, $logger);

        $mark = $this->mark();

        $this->assertTrue($mark->handle($posting));
        $this->assertFalse($mark->handle($posting));
        $this->assertSame(PostingStatus::Reviewed, $posting->refresh()->status);
    }

    public function test_it_logs_the_posting_and_the_revision_that_was_read(): void
    {
        $posting = $this->posting(PostingStatus::Ready);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('posting.reviewed', Mockery::on(function (array $context) use ($posting): bool {
                $this->assertSame($posting->id, $context['posting_id']);
                $this->assertSame($posting->current_revision_id, $context['revision_id']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->mark()->handle($posting);
    }

    /**
     * Reviewing says the owner read the CV. It is not a record of which CV
     * went anywhere, so it leaves `applied_revision_id` alone.
     */
    public function test_it_records_no_applied_revision(): void
    {
        $posting = $this->posting(PostingStatus::Ready);

        $this->mark()->handle($posting);

        $this->assertNull($posting->refresh()->applied_revision_id);
    }

    private function posting(PostingStatus $status): Posting
    {
        $posting = Posting::factory()->status($status)->create();

        $revision = Revision::factory()
            ->status(RevisionStatus::Ready)
            ->create(['posting_id' => $posting->id]);

        $posting->fill(['current_revision_id' => $revision->id])->save();

        return $posting;
    }

    private function mark(): MarkPostingReviewed
    {
        return $this->app->make(MarkPostingReviewed::class);
    }
}
