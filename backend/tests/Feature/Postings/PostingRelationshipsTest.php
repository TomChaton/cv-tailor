<?php

namespace Tests\Feature\Postings;

use App\Models\MasterCvVersion;
use App\Models\Posting;
use App\Models\Revision;
use App\Postings\PostingStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_posting_reaches_its_revisions_and_they_reach_it(): void
    {
        $posting = Posting::factory()->create();
        $revision = Revision::factory()->for($posting)->create();

        $this->assertTrue($posting->revisions->first()?->is($revision));
        $this->assertTrue($revision->posting?->is($posting));
    }

    /**
     * The current revision is the one the owner is looking at; the applied one
     * is what actually went to the employer. They are separate columns because
     * the version that was sent is the one that matters later.
     */
    public function test_a_posting_tracks_its_current_and_applied_revisions_separately(): void
    {
        $posting = Posting::factory()->create();
        $first = Revision::factory()->for($posting)->create();
        $second = Revision::factory()->for($posting)->create();

        $posting->update([
            'current_revision_id' => $second->getKey(),
            'applied_revision_id' => $first->getKey(),
            'status' => PostingStatus::Applied,
        ]);

        $posting->refresh();

        $this->assertTrue($posting->currentRevision?->is($second));
        $this->assertTrue($posting->appliedRevision?->is($first));
        $this->assertSame(PostingStatus::Applied, $posting->status);
    }

    public function test_a_revision_names_the_master_cv_version_it_came_from(): void
    {
        $version = MasterCvVersion::factory()->create();
        $revision = Revision::factory()->for($version)->create();

        $this->assertTrue($revision->masterCvVersion?->is($version));
        $this->assertTrue($version->revisions->first()?->is($revision));
    }

    public function test_the_dedupe_hash_admits_one_posting_per_job(): void
    {
        $hash = hash('sha256', 'a normalised posting');
        Posting::factory()->create(['dedupe_hash' => $hash]);

        $this->expectException(QueryException::class);

        Posting::factory()->create(['dedupe_hash' => $hash]);
    }

    public function test_a_master_cv_version_is_stored_once_per_hash(): void
    {
        $version = MasterCvVersion::factory()->create();

        $this->expectException(QueryException::class);

        MasterCvVersion::factory()->create(['hash' => $version->hash]);
    }
}
