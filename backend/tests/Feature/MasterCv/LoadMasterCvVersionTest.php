<?php

namespace Tests\Feature\MasterCv;

use App\MasterCv\LoadMasterCvVersion;
use App\MasterCv\MasterCvIsNotUsable;
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
        $version = $this->load()->handle($this->fixturePath());

        $this->assertSame($this->parseFixture()->hash(), $version->hash);
        $this->assertSame($this->fixture(), $version->raw_content);
        $this->assertIsArray($version->parsed_json);
        $this->assertDatabaseCount('master_cv_versions', 1);
    }

    public function test_an_unchanged_cv_reuses_the_version_already_stored(): void
    {
        $first = $this->load()->handle($this->fixturePath());
        $second = $this->load()->handle($this->fixturePath());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('master_cv_versions', 1);
    }

    public function test_it_logs_what_it_parsed_as_counts(): void
    {
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
                $this->assertSame(44, $context['skills']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->load()->handle($this->fixturePath());
    }

    /**
     * The gate: a CV that fails lint never becomes a version, so no revision
     * can be generated from one.
     */
    public function test_it_refuses_a_cv_that_fails_lint(): void
    {
        $this->expectException(MasterCvIsNotUsable::class);

        try {
            $this->load()->handle($this->fixturePath('master-cv/broken-missing-id.md'));
        } finally {
            $this->assertDatabaseCount('master_cv_versions', 0);
        }
    }

    public function test_it_refuses_a_cv_it_cannot_read(): void
    {
        $this->expectException(MasterCvIsNotUsable::class);

        $this->load()->handle('/var/lib/cv-tailor/nothing-here.md');
    }

    public function test_it_reads_the_configured_cv_when_given_no_path(): void
    {
        config(['cv.master_cv.path' => $this->fixturePath('master-cv/minimal.md')]);

        $version = $this->load()->handle();

        $this->assertSame($this->fixture('master-cv/minimal.md'), $version->raw_content);
    }

    private function load(): LoadMasterCvVersion
    {
        return app(LoadMasterCvVersion::class);
    }
}
