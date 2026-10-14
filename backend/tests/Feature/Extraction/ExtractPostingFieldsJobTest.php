<?php

namespace Tests\Feature\Extraction;

use App\Jobs\ExtractPostingFields;
use App\Llm\LlmFailure;
use App\Llm\LlmFailureKind;
use App\Llm\Testing\FakeLlmClient;
use App\Models\Posting;
use App\Observability\EventLogger;
use App\Postings\MarkPostingWanted;
use App\Postings\PostingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ExtractPostingFieldsJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = $this->app->make(FakeLlmClient::class);
    }

    public function test_it_extracts_the_posting_it_was_dispatched_for(): void
    {
        $this->llm->willAnswerWith('extraction');

        $posting = $this->captured();

        $this->handleJob($posting);

        $this->assertSame('Senior PHP Engineer', $posting->fresh()?->title);
    }

    /**
     * A failure must not lose a posting. The owner recognises a job from its
     * URL, and a posting they recognise is still worth a CV.
     */
    public function test_a_failure_leaves_the_posting_listed_and_markable_as_wanted(): void
    {
        $this->llm->willFailWith(LlmFailureKind::ServerError);

        $posting = $this->captured();

        try {
            $this->handleJob($posting);

            $this->fail('A provider failure should reach the queue.');
        } catch (LlmFailure) {
            // Rethrown so the queue retries it on its own terms.
        }

        $stored = $posting->fresh();

        $this->assertNotNull($stored);
        $this->assertNull($stored->extracted_at);
        $this->assertNull($stored->title);
        $this->assertSame(PostingStatus::Captured, $stored->status);

        $this->app->make(MarkPostingWanted::class)->handle($stored);

        $this->assertSame(PostingStatus::Wanted, $stored->fresh()?->status);
    }

    public function test_it_warns_with_the_failure_class_and_the_attempt_count(): void
    {
        $this->llm->willFailWith(LlmFailureKind::RateLimit);

        $posting = $this->captured();

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->with('llm.failed', Mockery::any(), Mockery::any());
        $logger->shouldReceive('warning')->with('llm.failed', Mockery::any());
        $logger->shouldReceive('warning')
            ->once()
            ->with('posting.extraction_failed', Mockery::on(function (array $context) use ($posting): bool {
                $this->assertSame([
                    'posting_id' => $posting->id,
                    'failure' => 'rate_limit',
                    'attempts' => 1,
                ], $context);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        try {
            $this->handleJob($posting);
        } catch (LlmFailure) {
            // Asserted above.
        }
    }

    private function captured(): Posting
    {
        return Posting::factory()->awaitingExtraction()->create([
            'page_text' => 'Senior PHP Engineer at Northwind Systems. We run a clearing platform.',
        ]);
    }

    private function handleJob(Posting $posting): void
    {
        $this->app->call([new ExtractPostingFields($posting), 'handle']);
    }
}
