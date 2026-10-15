<?php

namespace Tests\Feature\Observability;

use App\Batching\LlmBatchState;
use App\Models\LlmBatch;
use App\Models\Posting;
use App\Models\Revision;
use App\Observability\Status\BatchesSection;
use App\Observability\Status\CountByStatusSection;
use App\Observability\Status\PostingsSection;
use App\Observability\Status\RevisionsSection;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CountByStatusSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_zero_for_every_posting_status_nothing_is_in(): void
    {
        $rows = app(PostingsSection::class)->rows();

        $this->assertSame(PostingStatus::values(), array_keys($rows));
        $this->assertSame(array_fill_keys(PostingStatus::values(), '0'), $rows);
    }

    public function test_it_counts_postings_by_status(): void
    {
        Posting::factory()->count(2)->create();
        Posting::factory()->status(PostingStatus::Discarded)->create();

        $rows = app(PostingsSection::class)->rows();

        $this->assertSame('2', $rows['captured']);
        $this->assertSame('1', $rows['discarded']);
        $this->assertSame('0', $rows['applied']);
    }

    /**
     * One posting in each status at once, so a status the enum has but the
     * report cannot count is a failure here rather than a zero nobody
     * questions.
     */
    public function test_it_counts_a_posting_in_every_status_the_enum_has(): void
    {
        foreach (PostingStatus::cases() as $status) {
            Posting::factory()->status($status)->create();
        }

        $rows = app(PostingsSection::class)->rows();

        foreach (PostingStatus::values() as $status) {
            $this->assertSame('1', $rows[$status], $status.' was not counted');
        }
    }

    /**
     * As above, for revisions: a revision in a status the report cannot count
     * has vanished from the counts, which is the "why is nothing happening"
     * trap this command exists to answer.
     */
    public function test_it_counts_a_revision_in_every_status_the_enum_has(): void
    {
        foreach (RevisionStatus::cases() as $status) {
            Revision::factory()->status($status)->create();
        }

        $rows = app(RevisionsSection::class)->rows();

        foreach (RevisionStatus::values() as $status) {
            $this->assertSame('1', $rows[$status], $status.' was not counted');
        }
    }

    public function test_it_reports_a_zero_for_every_revision_status_nothing_is_in(): void
    {
        $rows = app(RevisionsSection::class)->rows();

        $this->assertSame(array_fill_keys(RevisionStatus::values(), '0'), $rows);
    }

    public function test_it_counts_revisions_by_status(): void
    {
        Revision::factory()->count(2)->create();
        Revision::factory()->status(RevisionStatus::Failed)->create();

        $rows = app(RevisionsSection::class)->rows();

        $this->assertSame('2', $rows['pending']);
        $this->assertSame('1', $rows['failed']);
        $this->assertSame('0', $rows['ready']);
    }

    public function test_it_counts_batches_by_state(): void
    {
        LlmBatch::factory()->count(2)->create();
        LlmBatch::factory()->inState(LlmBatchState::Ended)->create();

        $rows = app(BatchesSection::class)->rows();

        $this->assertSame('2', $rows['submitted']);
        $this->assertSame('1', $rows['ended']);
        $this->assertSame('0', $rows['collected']);
    }

    /**
     * One row in each state at once: a count that reads the wrong column
     * returns zero for all of them, and a count that reads the right one
     * cannot.
     */
    public function test_it_counts_a_batch_in_every_state_the_enum_has(): void
    {
        foreach (LlmBatchState::cases() as $state) {
            LlmBatch::factory()->inState($state)->create();
        }

        $rows = app(BatchesSection::class)->rows();

        foreach (LlmBatchState::values() as $state) {
            $this->assertSame('1', $rows[$state], $state.' was not counted');
        }
    }

    /**
     * A column the table does not have is a mistake that SQLite answers with
     * a zero, so the section has to answer it with an exception instead.
     */
    public function test_a_section_counting_a_column_its_table_lacks_fails_loudly(): void
    {
        Posting::factory()->create();

        $section = new class extends CountByStatusSection
        {
            public function heading(): string
            {
                return 'Nonexistent';
            }

            protected function query(): Builder
            {
                return Posting::query();
            }

            protected function column(): string
            {
                return 'state';
            }

            protected function statuses(): array
            {
                return ['captured'];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('postings has no column state');

        $section->rows();
    }
}
