<?php

namespace Tests\Feature\Api;

use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Observability\EventLogger;
use App\Owner\IssueExtensionToken;
use App\Postings\PostingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CapturePostingTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/postings';

    private const EXTRACTED_TEXT = "Senior PHP Engineer\n\nWe are looking for a PHP engineer to join the platform team.";

    private const RAW_HTML = '<div class="description"><p>We are looking for a PHP engineer.</p></div>';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Issued before any test replaces the event logger: issuing a token
        // writes a log line of its own, and a test watching for capture's
        // lines should not see it.
        $this->token = app(IssueExtensionToken::class)->handle(User::factory()->create(), 'extension');
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

    public function test_it_records_the_raw_material_and_nothing_worked_out_from_it(): void
    {
        $this->capture($this->payload(['has_hidden_text' => true]))->assertCreated();

        $posting = Posting::sole();

        $this->assertSame('https://jobs.example.com/vacancy/7781', $posting->url);
        $this->assertSame('jobs.example.com', $posting->source_site);
        $this->assertSame('Senior PHP Engineer', $posting->title);
        $this->assertSame('Example Ltd', $posting->company);
        $this->assertSame('Manchester', $posting->location);
        $this->assertSame('£70,000 – £85,000', $posting->salary_text);
        $this->assertSame(self::EXTRACTED_TEXT, $posting->extracted_text);
        $this->assertSame(self::RAW_HTML, $posting->raw_html);
        $this->assertTrue($posting->has_hidden_text);
        $this->assertSame(PostingStatus::Captured, $posting->status);
    }

    public function test_metadata_the_page_did_not_offer_is_stored_as_absent(): void
    {
        $this->capture($this->payload([
            'title' => null,
            'company' => null,
            'location' => null,
            'salary_text' => null,
        ]))->assertCreated();

        $posting = Posting::sole();

        $this->assertNull($posting->title);
        $this->assertNull($posting->company);
        $this->assertNull($posting->location);
        $this->assertNull($posting->salary_text);
    }

    public function test_capture_creates_no_revision(): void
    {
        $this->capture()->assertCreated();

        $this->assertSame(0, Revision::count());
        $this->assertNull(Posting::sole()->current_revision_id);
    }

    /**
     * A posting captured while the master CV is unusable is still a posting
     * worth having, so capture is the one step the state of the CV cannot stop.
     */
    public function test_a_capture_succeeds_when_the_master_cv_cannot_be_read(): void
    {
        config(['cv.master_cv.path' => '/var/lib/cv-tailor/no-such-cv.md']);

        $this->capture()->assertCreated();

        $this->assertSame(1, Posting::count());
    }

    public function test_the_same_posting_captured_twice_answers_200_with_the_first_one(): void
    {
        $this->capture()->assertCreated();
        $first = Posting::sole();

        $this->capture()
            ->assertOk()
            ->assertExactJson(['id' => $first->id, 'status' => 'captured']);

        $this->assertSame(1, Posting::count());
    }

    public function test_the_same_posting_on_another_board_is_the_posting_already_held(): void
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

    public function test_a_different_posting_is_a_posting_of_its_own(): void
    {
        $this->capture()->assertCreated();

        $this->capture($this->payload([
            'title' => 'Senior Go Engineer',
            'extracted_text' => 'We are looking for a Go engineer to join the platform team.',
        ]))->assertCreated();

        $this->assertSame(2, Posting::count());
    }

    public function test_a_body_missing_what_a_capture_needs_is_refused_naming_the_fields(): void
    {
        $this->capture([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'url', 'source_site', 'extracted_text', 'raw_html', 'has_hidden_text',
            ]);

        $this->assertSame(0, Posting::count());
    }

    public function test_a_url_that_is_not_one_is_refused(): void
    {
        $this->capture($this->payload(['url' => 'not a url']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_metadata_longer_than_its_column_is_refused(): void
    {
        $this->capture($this->payload(['company' => str_repeat('a', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company']);
    }

    public function test_a_body_past_the_limit_is_refused(): void
    {
        config(['cv.api.max_body_bytes' => 512]);

        $this->capture($this->payload(['raw_html' => '<div>'.str_repeat('x', 1024).'</div>']))
            ->assertStatus(413);

        $this->assertSame(0, Posting::count());
    }

    public function test_a_body_within_the_limit_is_read(): void
    {
        config(['cv.api.max_body_bytes' => 4096]);

        $this->capture()->assertCreated();
    }

    public function test_it_logs_the_capture_with_the_sizes_that_show_a_truncated_extraction(): void
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
                $this->assertSame(strlen(self::RAW_HTML), $context['raw_html_bytes']);
                $this->assertSame(mb_strlen(self::EXTRACTED_TEXT), $context['extracted_text_chars']);

                // Never the posting's own text, its HTML or its URL: the row
                // holds all three and a log line does not need them.
                $this->assertSame([
                    'posting_id', 'source_site', 'dedupe_hash', 'has_hidden_text',
                    'raw_html_bytes', 'extracted_text_chars',
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
            ->with('posting.rejected', ['fields' => ['url', 'raw_html']]);

        $this->instance(EventLogger::class, $logger);

        $this->capture($this->payload(['url' => 'not a url', 'raw_html' => '']))
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

        $this->capture($this->payload(['raw_html' => '<div>'.str_repeat('x', 1024).'</div>']))
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
            'extracted_text' => self::EXTRACTED_TEXT,
            'raw_html' => self::RAW_HTML,
            'has_hidden_text' => false,
            'title' => 'Senior PHP Engineer',
            'company' => 'Example Ltd',
            'location' => 'Manchester',
            'salary_text' => '£70,000 – £85,000',
            ...$overrides,
        ];
    }
}
