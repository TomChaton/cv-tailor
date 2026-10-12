<?php

namespace Tests\Feature\Observability;

use App\Models\Posting;
use App\Models\Revision;
use App\Observability\Status\PostingsSection;
use App\Observability\Status\RevisionsSection;
use App\Postings\PostingStatus;
use App\Revisions\RevisionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
