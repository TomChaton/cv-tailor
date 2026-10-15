<?php

namespace Tests\Feature\Postings;

use App\Generation\QueuePostings;
use App\Models\Posting;
use App\Models\Revision;
use App\Postings\DiscardPosting;
use App\Postings\MarkPostingWanted;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

/**
 * The two decisions the owner takes about a captured posting: that it is worth
 * a CV, and that it is not worth anything.
 */
class PostingGateTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_marking_a_captured_posting_makes_it_wanted(): void
    {
        $posting = Posting::factory()->create();

        $this->assertTrue(app(MarkPostingWanted::class)->handle($posting));
        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }

    public function test_a_wanted_posting_is_what_the_queue_then_picks_up(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        $posting = Posting::factory()->create();

        app(MarkPostingWanted::class)->handle($posting);

        $this->assertSame(1, app(QueuePostings::class)->handle()->postings);
        $this->assertSame(PostingStatus::Queued, $posting->refresh()->status);
    }

    /**
     * Every later status either already has a revision or is the owner's own
     * verdict on one, and marking from there would walk the tracker
     * backwards.
     */
    #[DataProvider('statusesThatCannotBeMarked')]
    public function test_marking_a_posting_that_is_not_captured_does_nothing(PostingStatus $status): void
    {
        $posting = Posting::factory()->status($status)->create();

        $this->assertFalse(app(MarkPostingWanted::class)->handle($posting));
        $this->assertSame($status, $posting->refresh()->status);
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

    public function test_marking_logs_the_posting(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->create();

        app(MarkPostingWanted::class)->handle($posting);

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.wanted'
                && $context['event'] === 'posting.wanted'
                && $context['posting_id'] === $posting->id,
        )->once();
    }

    public function test_marking_a_posting_twice_says_so_once(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->create();
        $mark = app(MarkPostingWanted::class);

        $this->assertTrue($mark->handle($posting));
        $this->assertFalse($mark->handle($posting->refresh()));

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message): bool => $message === 'posting.wanted',
        )->once();
    }

    #[DataProvider('statusesThatCanBeDiscarded')]
    public function test_a_posting_can_be_discarded_from_any_other_status(PostingStatus $status): void
    {
        $posting = Posting::factory()->status($status)->create();

        $this->assertTrue(app(DiscardPosting::class)->handle($posting));
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
     * The spend the `wanted` gate exists to prevent, arriving by the other
     * door: a revision left `pending` would be submitted and paid for, and its
     * result applied to a posting that was thrown away.
     */
    public function test_discarding_abandons_the_generation_nobody_is_waiting_for(): void
    {
        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        $revision = Revision::factory()->create(['posting_id' => $posting->id]);

        $this->assertTrue(app(DiscardPosting::class)->handle($posting));

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Abandoned, $stored->status);
        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);
    }

    /**
     * Abandoning is a decision, not a failure. A failure spends an attempt
     * because it bought something that did not work; this bought nothing.
     */
    public function test_an_abandoned_revision_counts_no_attempt(): void
    {
        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        $revision = Revision::factory()->create(['posting_id' => $posting->id, 'attempts' => 0]);

        app(DiscardPosting::class)->handle($posting);

        $this->assertSame(0, $revision->fresh()?->attempts);
    }

    /**
     * It has been paid for, and its submission row has to be resolved or its
     * batch never closes. So it runs to completion, and `MarkPostingReady` is
     * what declines to move the posting when the result lands.
     */
    public function test_discarding_leaves_a_revision_that_has_already_gone_out(): void
    {
        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        $submitted = Revision::factory()
            ->status(RevisionStatus::Submitted)
            ->create(['posting_id' => $posting->id]);

        $this->assertTrue(app(DiscardPosting::class)->handle($posting));

        $this->assertSame(RevisionStatus::Submitted, $submitted->fresh()?->status);
    }

    /**
     * Only `pending` is abandoned, so a posting discarded after its CV was
     * read keeps the record of what was spent on it.
     */
    #[DataProvider('revisionStatusesThatAreNotPending')]
    public function test_discarding_leaves_a_revision_that_is_not_pending(RevisionStatus $status): void
    {
        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        $revision = Revision::factory()->status($status)->create(['posting_id' => $posting->id]);

        app(DiscardPosting::class)->handle($posting);

        $this->assertSame($status, $revision->fresh()?->status);
    }

    /**
     * @return iterable<string, array{RevisionStatus}>
     */
    public static function revisionStatusesThatAreNotPending(): iterable
    {
        foreach (RevisionStatus::cases() as $status) {
            if ($status !== RevisionStatus::Pending) {
                yield $status->value => [$status];
            }
        }
    }

    /**
     * One line per revision, written where the status is written.
     */
    public function test_abandoning_logs_the_revision_and_the_status_it_left(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        $first = Revision::factory()->create(['posting_id' => $posting->id]);
        $second = Revision::factory()->create(['posting_id' => $posting->id]);

        app(DiscardPosting::class)->handle($posting);

        foreach ([$first, $second] as $revision) {
            $log->shouldHaveReceived('log')->withArgs(
                fn (string $level, string $message, array $context): bool => $message === 'revision.abandoned'
                    && $context['event'] === 'revision.abandoned'
                    && $context['revision_id'] === $revision->id
                    && $context['posting_id'] === $posting->id
                    && $context['from'] === 'pending',
            )->once();
        }
    }

    /**
     * A discard that abandoned generation is a different event in kind from
     * one that abandoned none, and the owner is paying for the difference.
     */
    public function test_discarding_logs_how_much_generation_went_with_it(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        Revision::factory()->create(['posting_id' => $posting->id]);
        Revision::factory()->status(RevisionStatus::Submitted)->create(['posting_id' => $posting->id]);

        app(DiscardPosting::class)->handle($posting);

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.discarded'
                && $context['revisions_abandoned'] === 1,
        )->once();
    }

    public function test_discarding_a_posting_with_no_revision_abandons_nothing(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->wanted()->create();

        $this->assertTrue(app(DiscardPosting::class)->handle($posting));
        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.discarded'
                && $context['revisions_abandoned'] === 0,
        )->once();

        $log->shouldNotHaveReceived('log', [
            Mockery::any(),
            'revision.abandoned',
            Mockery::any(),
        ]);
    }

    /**
     * Nothing abandons a revision belonging to a posting that is still live.
     */
    public function test_discarding_one_posting_leaves_anothers_revision_pending(): void
    {
        $discarded = Posting::factory()->status(PostingStatus::Queued)->create();
        Revision::factory()->create(['posting_id' => $discarded->id]);

        $other = Posting::factory()->status(PostingStatus::Queued)->create();
        $waiting = Revision::factory()->create(['posting_id' => $other->id]);

        app(DiscardPosting::class)->handle($discarded);

        $this->assertSame(RevisionStatus::Pending, $waiting->fresh()?->status);
    }

    /**
     * A posting that moved between being read and being discarded is left to
     * whatever moved it, and so is its generation.
     */
    public function test_a_posting_that_moved_since_it_was_read_abandons_nothing(): void
    {
        $posting = Posting::factory()->status(PostingStatus::Queued)->create();
        $revision = Revision::factory()->create(['posting_id' => $posting->id]);

        Posting::query()->whereKey($posting->id)->update(['status' => PostingStatus::Ready]);

        $this->assertFalse(app(DiscardPosting::class)->handle($posting));
        $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
    }

    public function test_discarding_a_discarded_posting_does_nothing(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->status(PostingStatus::Discarded)->create();

        $this->assertFalse(app(DiscardPosting::class)->handle($posting));

        $log->shouldNotHaveReceived('log');
    }

    /**
     * A posting discarded on sight and one discarded after its CV was read
     * are the same decision about very different things, and the status it
     * left is what tells the two apart in the log.
     */
    public function test_discarding_logs_the_status_it_was_in(): void
    {
        $log = Log::spy();

        $posting = Posting::factory()->wanted()->create();

        app(DiscardPosting::class)->handle($posting);

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.discarded'
                && $context['event'] === 'posting.discarded'
                && $context['posting_id'] === $posting->id
                && $context['from'] === 'wanted',
        )->once();
    }

    /**
     * Whatever else was true of it. The guarantee is the status itself: the
     * sweep reads `wanted`, and discarding moves a posting off it.
     */
    public function test_a_discarded_posting_is_never_queued(): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        $posting = Posting::factory()->wanted()->create();

        app(DiscardPosting::class)->handle($posting);

        $this->assertSame(0, app(QueuePostings::class)->handle()->postings);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame(PostingStatus::Discarded, $posting->refresh()->status);
    }

    /**
     * A posting that moved between being read and being discarded is left to
     * whatever moved it, rather than reported as discarded from a status it
     * had already left.
     */
    public function test_a_posting_that_moved_since_it_was_read_is_left_alone(): void
    {
        $posting = Posting::factory()->create();

        Posting::query()->whereKey($posting->id)->update(['status' => PostingStatus::Wanted]);

        $this->assertFalse(app(DiscardPosting::class)->handle($posting));
        $this->assertSame(PostingStatus::Wanted, $posting->refresh()->status);
    }
}
