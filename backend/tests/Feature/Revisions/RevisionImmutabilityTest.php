<?php

namespace Tests\Feature\Revisions;

use App\Models\Revision;
use App\Revisions\CompletedRevisionIsImmutable;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Tests\TestCase;

class RevisionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{RevisionStatus}>
     */
    public static function completeStatuses(): array
    {
        return [[RevisionStatus::Ready], [RevisionStatus::Failed]];
    }

    /**
     * @return list<array{RevisionStatus}>
     */
    public static function incompleteStatuses(): array
    {
        return [
            [RevisionStatus::Pending],
            [RevisionStatus::Submitted],
            [RevisionStatus::Generated],
            [RevisionStatus::Validated],
        ];
    }

    #[DataProvider('completeStatuses')]
    public function test_a_complete_revision_refuses_to_be_updated(RevisionStatus $status): void
    {
        $revision = Revision::factory()->status($status)->create();

        try {
            $revision->update(['pdf_path' => 'postings/1/revisions/1.pdf']);
            $this->fail('The guard let a complete revision through.');
        } catch (CompletedRevisionIsImmutable) {
            // Asserted below: the refusal must also leave the row alone.
        }

        $this->assertNull($revision->fresh()?->pdf_path);
    }

    #[DataProvider('incompleteStatuses')]
    public function test_a_revision_that_is_not_complete_accepts_an_update(RevisionStatus $status): void
    {
        $revision = Revision::factory()->status($status)->create();

        $revision->update(['pdf_path' => 'postings/1/revisions/1.pdf']);

        $this->assertSame('postings/1/revisions/1.pdf', $revision->fresh()?->pdf_path);
    }

    #[DataProvider('completeStatuses')]
    public function test_a_revision_may_be_moved_into_a_complete_status(RevisionStatus $status): void
    {
        $revision = Revision::factory()->status(RevisionStatus::Validated)->create();

        $revision->update(['status' => $status]);

        $this->assertSame($status, $revision->fresh()?->status);
    }

    public function test_a_complete_revision_may_have_a_child(): void
    {
        $parent = Revision::factory()->status(RevisionStatus::Ready)->create();

        $child = Revision::factory()->childOf($parent)->create();

        $this->assertTrue($child->parent?->is($parent));
        $this->assertCount(1, $parent->children);
    }

    public function test_a_rejected_write_is_logged_as_a_warning(): void
    {
        $revision = Revision::factory()->status(RevisionStatus::Ready)->create();

        $log = Log::spy();

        try {
            $revision->update(['pdf_path' => 'nope.pdf']);
            $this->fail('The guard let a complete revision through.');
        } catch (CompletedRevisionIsImmutable) {
            // The log line is the point of the test; the exception is covered above.
        }

        $log->shouldHaveReceived('log')->once()->withArgs(
            fn (string $level, string $message, array $context): bool => $level === LogLevel::WARNING
                && $message === 'revision.mutation_rejected'
                && $context['event'] === 'revision.mutation_rejected'
                && $context['revision_id'] === $revision->getKey()
                && $context['status'] === 'ready',
        );
    }
}
