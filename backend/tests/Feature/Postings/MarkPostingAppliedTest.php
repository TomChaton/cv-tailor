<?php

namespace Tests\Feature\Postings;

use App\Models\Posting;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Postings\MarkPostingApplied;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarkPostingAppliedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Applying without marking the CV read first is an ordinary thing to do.
     *
     * @return list<array{PostingStatus}>
     */
    public static function statusesThatApply(): array
    {
        return [
            [PostingStatus::Ready],
            [PostingStatus::Reviewed],
        ];
    }

    #[DataProvider('statusesThatApply')]
    public function test_a_ready_or_reviewed_posting_becomes_applied(PostingStatus $status): void
    {
        $posting = $this->posting($status);

        $this->assertTrue($this->mark()->handle($posting));
        $this->assertSame(PostingStatus::Applied, $posting->refresh()->status);
    }

    /**
     * **The revision is the point of the record.** A tracker that knows a job
     * was applied for but not with which CV is half a record.
     */
    #[DataProvider('statusesThatApply')]
    public function test_it_records_the_revision_that_was_sent(PostingStatus $status): void
    {
        $posting = $this->posting($status);
        $current = $posting->current_revision_id;

        $this->mark()->handle($posting);

        $this->assertSame($current, $posting->refresh()->applied_revision_id);
    }

    /**
     * @return list<array{PostingStatus}>
     */
    public static function statusesThatDoNotMove(): array
    {
        return [
            [PostingStatus::Captured],
            [PostingStatus::Wanted],
            [PostingStatus::Queued],
            [PostingStatus::Applied],
            [PostingStatus::Discarded],
        ];
    }

    #[DataProvider('statusesThatDoNotMove')]
    public function test_nothing_else_moves(PostingStatus $status): void
    {
        $posting = $this->posting($status);

        $this->assertFalse($this->mark()->handle($posting));
        $this->assertSame($status, $posting->refresh()->status);
    }

    /**
     * An applied posting already carries the record of what was sent, and a
     * second call must not overwrite it with whatever is current now.
     */
    public function test_a_posting_already_applied_keeps_the_revision_it_recorded(): void
    {
        $posting = $this->posting(PostingStatus::Ready);
        $sent = $posting->current_revision_id;

        $this->assertTrue($this->mark()->handle($posting));

        $later = Revision::factory()
            ->status(RevisionStatus::Ready)
            ->create(['posting_id' => $posting->id]);

        $posting->refresh()->fill(['current_revision_id' => $later->id])->save();

        $this->assertFalse($this->mark()->handle($posting));
        $this->assertSame($sent, $posting->refresh()->applied_revision_id);
    }

    /**
     * There is nothing to record. A posting at `ready` has a current revision
     * by construction, so this is belt and braces rather than a state the
     * screen offers.
     */
    public function test_a_posting_with_no_current_revision_records_nothing(): void
    {
        $posting = Posting::factory()->status(PostingStatus::Ready)->create();

        $this->assertFalse($this->mark()->handle($posting));
        $this->assertSame(PostingStatus::Ready, $posting->refresh()->status);
        $this->assertNull($posting->applied_revision_id);
    }

    public function test_marking_it_twice_writes_once_and_says_so_once(): void
    {
        $posting = $this->posting(PostingStatus::Ready);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('posting.applied', Mockery::any());

        $this->instance(EventLogger::class, $logger);

        $mark = $this->mark();

        $this->assertTrue($mark->handle($posting));
        $this->assertFalse($mark->handle($posting));
    }

    public function test_it_logs_the_posting_and_the_revision_that_was_sent(): void
    {
        $posting = $this->posting(PostingStatus::Ready);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('posting.applied', Mockery::on(function (array $context) use ($posting): bool {
                $this->assertSame($posting->id, $context['posting_id']);
                $this->assertSame($posting->current_revision_id, $context['revision_id']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->mark()->handle($posting);
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

    private function mark(): MarkPostingApplied
    {
        return $this->app->make(MarkPostingApplied::class);
    }
}
