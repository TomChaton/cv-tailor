<?php

namespace Tests\Feature\Api;

use App\Jobs\ExtractPostingFields;
use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Observability\EventLogger;
use App\Owner\IssueExtensionToken;
use App\Postings\PostingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CapturePostingTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/postings';

    private const PAGE_TEXT = <<<'TEXT'
    All jobs Saved Sign in

    Senior PHP Engineer
    Example Ltd — Manchester

    We are looking for a PHP engineer to join the platform team.

    Example Ltd is an equal opportunities employer.
    TEXT;

    private const JSON_LD = [
        '@type' => 'JobPosting',
        'title' => 'Senior PHP Engineer',
        'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Example Ltd'],
    ];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Issued before any test replaces the event logger: issuing a token
        // writes a log line of its own, and a test watching for capture's
        // lines should not see it.
        $this->token = app(IssueExtensionToken::class)->handle(User::factory()->create(), 'extension');

        // A capture hands the posting to extraction. That it does so is
        // asserted here; what extraction then makes of the page is
        // extraction's own test, and it is the one thing that costs money.
        Queue::fake([ExtractPostingFields::class]);
    }

    public function test_a_capture_with_no_token_is_refused(): void
    {
        $this->postJson(self::URI, $this->payload())->assertUnauthorized();

        $this->assertSame(0, Posting::count());
    }

    public function test_a_capture_with_a_rejected_token_is_refused(): void
    {
        $this->postJson(self::URI, $this->payload(), ['Authorization' => 'Bearer 1|nonsense'])
            ->assertUnauthorized();

        $this->assertSame(0, Posting::count());
    }

    public function test_a_new_posting_answers_201_with_its_identity_and_nothing_else(): void
    {
        $response = $this->capture();

        $response->assertCreated();

        $response->assertExactJson(['id' => Posting::sole()->id, 'status' => 'captured']);
    }

    public function test_it_records_the_page_and_nothing_worked_out_from_it(): void
    {
        $this->capture($this->payload(['has_hidden_text' => true]))->assertCreated();

        $posting = Posting::sole();

        $this->assertSame('https://jobs.example.com/vacancy/7781', $posting->url);
        $this->assertSame('jobs.example.com', $posting->source_site);
        $this->assertSame(self::PAGE_TEXT, $posting->page_text);
        $this->assertSame(self::JSON_LD, $posting->json_ld);
        $this->assertTrue($posting->has_hidden_text);
        $this->assertSame(PostingStatus::Captured, $posting->status);

        // What the posting says is extraction's answer, and it has not run.
        $this->assertNull($posting->title);
        $this->assertNull($posting->company);
        $this->assertNull($posting->location);
        $this->assertNull($posting->salary_text);
        $this->assertNull($posting->description);
        $this->assertNull($posting->extracted_at);
    }

    public function test_it_hands_a_new_posting_to_extraction(): void
    {
        $this->capture()->assertCreated();

        Queue::assertPushed(
            ExtractPostingFields::class,
            fn (ExtractPostingFields $job): bool => $job->posting->is(Posting::sole()),
        );
    }

    /**
     * A board's URL carries session keys and tracking parameters, so it passes
     * 255 characters routinely and the column that holds it is `text`.
     */
    public function test_a_url_longer_than_a_varchar_is_captured_whole(): void
    {
        $url = 'https://jobs.example.com/vacancy/7781?'.http_build_query([
            'refId' => str_repeat('a', 120),
            'trackingId' => str_repeat('b', 120),
            'alternateChannel' => 'search',
        ]);

        $this->assertGreaterThan(255, strlen($url));

        $this->capture($this->payload(['url' => $url]))->assertCreated();

        $this->assertSame($url, Posting::sole()->url);
    }

    public function test_a_page_that_published_no_job_posting_object_is_captured_without_one(): void
    {
        $this->capture($this->payload(['json_ld' => null]))->assertCreated();

        $this->assertNull(Posting::sole()->json_ld);
    }

    /**
     * There is no floor and no "nothing found": which part of a page is the job
     * description is the backend's judgement to make now, and the extension
     * sends whatever the owner was looking at.
     */
    public function test_a_page_whose_text_is_one_line_is_captured_rather_than_refused(): void
    {
        $this->capture($this->payload(['page_text' => 'Senior PHP Engineer, Manchester.']))
            ->assertCreated();

        $this->assertSame('Senior PHP Engineer, Manchester.', Posting::sole()->page_text);
    }

    public function test_capture_creates_no_revision(): void
    {
        $this->capture()->assertCreated();

        $this->assertSame(0, Revision::count());
        $this->assertNull(Posting::sole()->current_revision_id);
    }

    /**
     * A posting captured before there is a master CV at all is still a posting
     * worth having, so capture is the one step the state of the CV cannot stop.
     */
    public function test_a_capture_succeeds_with_no_master_cv_stored(): void
    {
        $this->assertDatabaseCount('master_cv', 0);

        $this->capture()->assertCreated();

        $this->assertSame(1, Posting::count());
    }

    public function test_the_same_page_captured_twice_answers_200_with_the_first_one(): void
    {
        $this->capture()->assertCreated();
        $first = Posting::sole();

        $this->capture()
            ->assertOk()
            ->assertExactJson(['id' => $first->id, 'status' => 'captured']);

        $this->assertSame(1, Posting::count());
    }

    /**
     * The answer is already stored, so paying a model for it again is what the
     * dedupe hash exists to avoid.
     */
    public function test_the_second_capture_of_a_page_asks_nothing_of_a_model(): void
    {
        $this->capture()->assertCreated();
        $this->capture()->assertOk();

        Queue::assertPushed(ExtractPostingFields::class, 1);
    }

    public function test_the_same_page_under_another_url_is_the_posting_already_held(): void
    {
        $this->capture()->assertCreated();

        $this->capture($this->payload([
            'url' => 'https://another-board.example.com/ad/992',
            'source_site' => 'another-board.example.com',
        ]))->assertOk();

        $this->assertSame(1, Posting::count());
    }

    public function test_a_match_leaves_the_posting_as_it_stands(): void
    {
        $this->capture()->assertCreated();

        Posting::sole()->update(['status' => PostingStatus::Applied]);

        $this->capture()
            ->assertOk()
            ->assertExactJson(['id' => Posting::sole()->id, 'status' => 'applied']);

        $this->assertSame('jobs.example.com', Posting::sole()->source_site);
    }

    public function test_a_different_page_is_a_posting_of_its_own(): void
    {
        $this->capture()->assertCreated();

        $this->capture($this->payload([
            'page_text' => 'We are looking for a Go engineer to join the platform team.',
        ]))->assertCreated();

        $this->assertSame(2, Posting::count());
    }

    public function test_a_body_missing_what_a_capture_needs_is_refused_naming_the_fields(): void
    {
        $this->capture([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url', 'source_site', 'page_text', 'has_hidden_text']);

        $this->assertSame(0, Posting::count());
    }

    public function test_a_url_that_is_not_one_is_refused(): void
    {
        $this->capture($this->payload(['url' => 'not a url']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_structured_data_that_is_not_an_object_is_refused(): void
    {
        $this->capture($this->payload(['json_ld' => 'JobPosting']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['json_ld']);
    }

    public function test_a_body_past_the_limit_is_refused(): void
    {
        config(['cv.api.max_body_bytes' => 512]);

        $this->capture($this->payload(['page_text' => str_repeat('x', 1024)]))
            ->assertStatus(413);

        $this->assertSame(0, Posting::count());
    }

    public function test_a_body_within_the_limit_is_read(): void
    {
        config(['cv.api.max_body_bytes' => 4096]);

        $this->capture()->assertCreated();
    }

    public function test_it_logs_the_capture_with_the_size_that_shows_a_truncated_read(): void
    {
        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('posting.captured', Mockery::on(function (array $context): bool {
                $posting = Posting::sole();

                $this->assertSame($posting->id, $context['posting_id']);
                $this->assertSame('jobs.example.com', $context['source_site']);
                $this->assertSame($posting->dedupe_hash, $context['dedupe_hash']);
                $this->assertFalse($context['has_hidden_text']);
                $this->assertSame(mb_strlen(self::PAGE_TEXT), $context['page_text_chars']);
                $this->assertTrue($context['has_json_ld']);

                // Never the page's own text or its URL: the row holds both and
                // a log line does not need them.
                $this->assertSame([
                    'posting_id', 'source_site', 'dedupe_hash', 'has_hidden_text',
                    'page_text_chars', 'has_json_ld',
                ], array_keys($context));

                return true;
            }));
        $logger->shouldNotReceive('warning');

        $this->instance(EventLogger::class, $logger);

        $this->capture()->assertCreated();
    }

    public function test_it_logs_a_duplicate_as_the_ordinary_case_it_is(): void
    {
        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')->once()->with('posting.captured', Mockery::any());
        $logger->shouldReceive('log')
            ->once()
            ->with('posting.duplicate', Mockery::on(function (array $context): bool {
                $this->assertSame([
                    'posting_id' => Posting::sole()->id,
                    'source_site' => 'jobs.example.com',
                ], $context);

                return true;
            }));
        $logger->shouldNotReceive('warning');

        // Installed before the first capture, not between the two: a route
        // memoises the controller it resolved, so a second request in the same
        // test is served by the instance the first one built, whatever the
        // container has been told since.
        $this->instance(EventLogger::class, $logger);

        $this->capture()->assertCreated();
        $this->capture()->assertOk();
    }

    public function test_it_warns_about_a_rejected_capture_and_names_only_the_fields(): void
    {
        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('posting.rejected', ['fields' => ['url', 'page_text']]);

        $this->instance(EventLogger::class, $logger);

        $this->capture($this->payload(['url' => 'not a url', 'page_text' => '']))
            ->assertUnprocessable();
    }

    public function test_it_warns_about_a_body_past_the_limit_with_both_sizes(): void
    {
        config(['cv.api.max_body_bytes' => 512]);

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('request.too_large', Mockery::on(function (array $context): bool {
                $this->assertSame('api/v1/postings', $context['path']);
                $this->assertGreaterThan(512, $context['bytes']);
                $this->assertSame(512, $context['limit_bytes']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->capture($this->payload(['page_text' => str_repeat('x', 1024)]))
            ->assertStatus(413);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return TestResponse<Response>
     */
    private function capture(?array $payload = null): TestResponse
    {
        return $this->postJson(
            self::URI,
            $payload ?? $this->payload(),
            ['Authorization' => 'Bearer '.$this->token],
        );
    }

    /**
     * A fictional listing. Nothing in the repository derives from a real one.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'url' => 'https://jobs.example.com/vacancy/7781',
            'source_site' => 'jobs.example.com',
            'page_text' => self::PAGE_TEXT,
            'json_ld' => self::JSON_LD,
            'has_hidden_text' => false,
            ...$overrides,
        ];
    }
}
