<?php

namespace Tests\Feature\MasterCv;

use App\MasterCv\SaveMasterCv;
use App\Models\MasterCv;
use App\Observability\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MasterCvFixtures;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

class SaveMasterCvTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;

    public function test_it_stores_the_markdown_it_was_given(): void
    {
        $report = $this->save()->handle($this->fixture());

        $this->assertTrue($report->stored);
        $this->assertTrue($report->passes());
        $this->assertSame($this->fixture(), MasterCv::query()->sole()->markdown);
    }

    /**
     * Single-tenant: one owner, one CV, one row. A save replaces what is
     * stored rather than adding beside it.
     */
    public function test_saving_twice_leaves_one_record(): void
    {
        $this->save()->handle($this->fixture());
        $this->save()->handle($this->fixture('master-cv/minimal.md'));

        $this->assertDatabaseCount('master_cv', 1);
        $this->assertSame($this->fixture('master-cv/minimal.md'), MasterCv::query()->sole()->markdown);
    }

    /**
     * Saving is free and unversioned. A version is what a generation used, so
     * a revision's link to the text that produced it stays immutable however
     * often the CV is edited.
     */
    public function test_a_save_records_no_version(): void
    {
        $this->save()->handle($this->fixture());

        $this->assertDatabaseCount('master_cv_versions', 0);
    }

    /**
     * It is a draft. Refusing to store a CV with a problem in it would make
     * that problem unfixable.
     */
    public function test_a_cv_that_fails_lint_still_saves(): void
    {
        $report = $this->save()->handle($this->fixture('master-cv/broken-missing-id.md'));

        $this->assertFalse($report->passes());
        $this->assertSame(1, $report->missingIds());
        $this->assertDatabaseCount('master_cv', 1);
    }

    public function test_an_empty_cv_saves(): void
    {
        $report = $this->save()->handle('');

        $this->assertTrue($report->stored);
        $this->assertFalse($report->passes());
        $this->assertSame('', MasterCv::query()->sole()->markdown);
    }

    public function test_it_logs_the_size_and_the_verdict_and_never_the_content(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->save()->handle($this->fixture());

        $line = $log->first('master_cv.saved');

        $this->assertNotNull($line);
        $this->assertSame(mb_strlen($this->fixture()), $line['context']['characters']);
        $this->assertTrue($line['context']['passes_lint']);

        $this->assertStringNotContainsString(
            'Jordan Avery',
            (string) json_encode($log->lines),
        );
    }

    public function test_a_save_that_does_not_lint_says_so_in_the_same_line(): void
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        $this->save()->handle($this->fixture('master-cv/broken-missing-id.md'));

        $line = $log->first('master_cv.saved');

        $this->assertNotNull($line);
        $this->assertFalse($line['context']['passes_lint']);
    }

    private function save(): SaveMasterCv
    {
        return app(SaveMasterCv::class);
    }
}
