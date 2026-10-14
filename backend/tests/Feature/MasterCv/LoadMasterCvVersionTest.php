<?php

namespace Tests\Feature\MasterCv;

use App\MasterCv\LoadMasterCvVersion;
use App\MasterCv\MasterCvIsNotUsable;
use App\MasterCv\SaveMasterCv;
use App\Models\Revision;
use App\Observability\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class LoadMasterCvVersionTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_stores_the_parsed_cv_as_the_version_a_revision_can_point_at(): void
    {
        $this->storeMasterCv();

        $version = $this->load()->handle();

        $this->assertSame($this->parseFixture()->hash(), $version->hash);
        $this->assertSame($this->fixture(), $version->raw_content);
        $this->assertIsArray($version->parsed_json);
        $this->assertDatabaseCount('master_cv_versions', 1);
    }

    public function test_an_unchanged_cv_reuses_the_version_already_stored(): void
    {
        $this->storeMasterCv();

        $first = $this->load()->handle();
        $second = $this->load()->handle();

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('master_cv_versions', 1);
    }

    /**
     * What a revision points at is the text it was generated from, and
     * editing the CV afterwards cannot reach back and change it. This is why
     * the CV being edited and the versions that were used are separate
     * things.
     */
    public function test_a_revision_generated_before_an_edit_still_resolves_against_the_version_it_used(): void
    {
        $this->storeMasterCv();

        $used = $this->load()->handle();
        $revision = Revision::factory()->for($used)->create();

        app(SaveMasterCv::class)->handle($this->fixture('master-cv/minimal.md'));

        $this->assertSame($used->id, $revision->refresh()->master_cv_version_id);
        $this->assertSame($this->fixture(), $revision->masterCvVersion?->raw_content);

        $now = $this->load()->handle();

        $this->assertNotSame($used->id, $now->id);
        $this->assertSame($this->fixture('master-cv/minimal.md'), $now->raw_content);
        $this->assertDatabaseCount('master_cv_versions', 2);
    }

    public function test_it_logs_what_it_parsed_as_counts(): void
    {
        $this->storeMasterCv();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('master_cv.parsed', Mockery::on(function (array $context): bool {
                $this->assertIsInt($context['master_cv_version_id']);
                $this->assertSame($this->parseFixture()->hash(), $context['hash']);
                $this->assertTrue($context['stored']);
                $this->assertSame(4, $context['roles']);
                $this->assertSame(2, $context['projects']);
                $this->assertSame(25, $context['achievements']);
                $this->assertSame(7, $context['sub_bullets']);
                $this->assertSame(44, $context['skills_written']);
                $this->assertSame(0, $context['skills_derived']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->load()->handle();
    }

    /**
     * The gate: a CV that fails lint never becomes a version, so no revision
     * can be generated from one.
     */
    public function test_it_refuses_a_cv_that_fails_lint(): void
    {
        $this->storeMasterCv('master-cv/broken-missing-id.md');

        $this->expectException(MasterCvIsNotUsable::class);

        try {
            $this->load()->handle();
        } finally {
            $this->assertDatabaseCount('master_cv_versions', 0);
        }
    }

    public function test_it_refuses_an_installation_with_no_cv_stored(): void
    {
        $this->expectException(MasterCvIsNotUsable::class);

        $this->load()->handle();
    }

    private function load(): LoadMasterCvVersion
    {
        return app(LoadMasterCvVersion::class);
    }
}
