<?php

namespace Tests\Feature\Api;

use App\Generation\QueuePostings;
use App\Jobs\ExtractPostingFields;
use App\Models\Posting;
use App\Models\Revision;
use App\Models\User;
use App\Observability\EventLogger;
use App\Owner\IssueExtensionToken;
use App\Postings\AskedFor;
use App\Postings\PostingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\MasterCvFixtures;
use Tests\TestCase;

class CapturePostingTest extends TestCase
{
    use MasterCvFixtures;
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
                $this->assertSame('save', $context['asked_for']);
                $this->assertSame('jobs.example.com', $context['source_site']);
                $this->assertSame($posting->dedupe_hash, $context['dedupe_hash']);
                $this->assertFalse($context['has_hidden_text']);
                $this->assertSame(mb_strlen(self::PAGE_TEXT), $context['page_text_chars']);
                $this->assertTrue($context['has_json_ld']);

                // Never the page's own text or its URL: the row holds both and
                // a log line does not need them.
                $this->assertSame([
                    'posting_id', 'asked_for', 'source_site', 'dedupe_hash',
                    'has_hidden_text', 'page_text_chars', 'has_json_ld',
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
                    'asked_for' => 'save',
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
     * The popup offers three actions and the owner clicked the first: a posting
     * worth keeping a look at, and no decision to spend taken yet.
     */
    public function test_saving_leaves_the_posting_at_captured(): void
    {
        $this->capture($this->payload(['asked_for' => 'save']))
            ->assertCreated()
            ->assertExactJson(['id' => Posting::sole()->id, 'status' => 'captured']);

        $this->assertFalse(Posting::sole()->cover_letter_requested);
    }

    /**
     * A capture that says nothing behaves as Save, so a caller that knows
     * nothing about the field captures exactly as capture always has.
     */
    public function test_a_capture_that_names_no_action_behaves_as_saving(): void
    {
        $payload = $this->payload();

        $this->assertArrayNotHasKey('asked_for', $payload);

        $this->capture($payload)->assertCreated();

        $this->assertSame(PostingStatus::Captured, Posting::sole()->status);
        $this->assertFalse(Posting::sole()->cover_letter_requested);
    }

    public function test_asking_for_a_cv_captures_the_posting_as_wanted(): void
    {
        $this->capture($this->payload(['asked_for' => 'cv']))
            ->assertCreated()
            ->assertExactJson(['id' => Posting::sole()->id, 'status' => 'wanted']);

        $this->assertSame(PostingStatus::Wanted, Posting::sole()->status);
        $this->assertFalse(Posting::sole()->cover_letter_requested);
    }

    public function test_asking_for_a_cv_and_a_cover_letter_records_the_letter_as_well(): void
    {
        $this->capture($this->payload(['asked_for' => 'cv_and_cover_letter']))
            ->assertCreated()
            ->assertExactJson(['id' => Posting::sole()->id, 'status' => 'wanted']);

        $this->assertTrue(Posting::sole()->cover_letter_requested);
    }

    /**
     * The column records the request and nothing reads it on the way to a
     * model. A letter is a request of its own, and nothing writes one.
     */
    public function test_asking_for_a_cover_letter_generates_nothing(): void
    {
        $this->capture($this->payload(['asked_for' => 'cv_and_cover_letter']))->assertCreated();

        $this->assertSame(0, Revision::count());
        $this->assertNull(Posting::sole()->current_revision_id);
    }

    /**
     * `wanted` is what the sweep reads, so a capture that asked for a CV is a
     * posting the next run generates from — and one that did not is left where
     * it is for ever.
     */
    #[DataProvider('actionsAndWhetherTheSweepTakesThem')]
    public function test_the_sweep_takes_what_the_owner_asked_for(string $action, int $expected): void
    {
        $this->storeMasterCv('master-cv/minimal.md');

        $this->capture($this->payload(['asked_for' => $action]))->assertCreated();

        // As extraction would leave it. The sweep takes a posting there is a
        // job description for, and capture never has one.
        Posting::sole()->update(['description' => 'We are looking for a PHP engineer.']);

        $this->assertSame($expected, app(QueuePostings::class)->handle()->postings);
        $this->assertSame($expected, Revision::count());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function actionsAndWhetherTheSweepTakesThem(): iterable
    {
        yield 'save' => ['save', 0];
        yield 'cv' => ['cv', 1];
        yield 'cv and cover letter' => ['cv_and_cover_letter', 1];
    }

    /**
     * The owner has made the decision they were offered, on a posting they had
     * already captured.
     */
    public function test_asking_for_a_cv_for_a_posting_already_captured_makes_it_wanted(): void
    {
        $this->capture()->assertCreated();

        $this->capture($this->payload(['asked_for' => 'cv']))
            ->assertOk()
            ->assertExactJson(['id' => Posting::sole()->id, 'status' => 'wanted']);

        $this->assertSame(1, Posting::count());
    }

    /**
     * One event for one decision: the same line the review UI's Want action
     * writes, and written once however it was reached.
     */
    public function test_a_capture_that_asks_for_a_cv_logs_the_posting_as_wanted_once(): void
    {
        $log = Log::spy();

        $this->capture($this->payload(['asked_for' => 'cv']))->assertCreated();
        $this->capture($this->payload(['asked_for' => 'cv']))->assertOk();

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.wanted'
                && $context['event'] === 'posting.wanted'
                && $context['posting_id'] === Posting::sole()->id,
        )->once();
    }

    public function test_a_capture_that_asks_for_nothing_logs_no_posting_as_wanted(): void
    {
        $log = Log::spy();

        $this->capture()->assertCreated();

        $log->shouldNotHaveReceived('log', [Mockery::any(), 'posting.wanted', Mockery::any()]);
    }

    /**
     * Every later state either already has a revision or is the owner's own
     * verdict on one, and `discarded` is the owner's too: a capture is not an
     * appeal.
     */
    #[DataProvider('statusesACaptureDoesNotMove')]
    public function test_asking_for_a_cv_never_moves_a_posting_backwards(PostingStatus $status): void
    {
        $this->capture()->assertCreated();

        Posting::sole()->update(['status' => $status]);

        $this->capture($this->payload(['asked_for' => 'cv']))
            ->assertOk()
            ->assertExactJson(['id' => Posting::sole()->id, 'status' => $status->value]);

        $this->assertSame($status, Posting::sole()->status);
    }

    /**
     * @return iterable<string, array{PostingStatus}>
     */
    public static function statusesACaptureDoesNotMove(): iterable
    {
        foreach (PostingStatus::cases() as $status) {
            if ($status !== PostingStatus::Captured) {
                yield $status->value => [$status];
            }
        }
    }

    /**
     * The owner has said something new about a posting they already had, and
     * the status being left alone is not a reason to throw it away.
     */
    public function test_a_cover_letter_is_recorded_even_where_the_status_is_left_alone(): void
    {
        $this->capture()->assertCreated();

        Posting::sole()->update(['status' => PostingStatus::Applied]);

        $this->capture($this->payload(['asked_for' => 'cv_and_cover_letter']))->assertOk();

        $this->assertSame(PostingStatus::Applied, Posting::sole()->status);
        $this->assertTrue(Posting::sole()->cover_letter_requested);
    }

    /**
     * A posting the owner asked a letter for has asked for one. A later capture
     * of the same page asking for less has not unasked.
     */
    public function test_a_later_capture_does_not_withdraw_the_request(): void
    {
        $this->capture($this->payload(['asked_for' => 'cv_and_cover_letter']))->assertCreated();

        $this->capture($this->payload(['asked_for' => 'save']))->assertOk();

        $this->assertTrue(Posting::sole()->cover_letter_requested);
    }

    public function test_an_action_the_popup_does_not_offer_is_refused(): void
    {
        $this->capture($this->payload(['asked_for' => 'cover_letter']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asked_for']);

        $this->assertSame(0, Posting::count());
    }

    public function test_it_logs_which_of_the_three_actions_was_asked_for(): void
    {
        $log = Log::spy();

        $this->capture($this->payload(['asked_for' => 'cv_and_cover_letter']))->assertCreated();

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.captured'
                && $context['asked_for'] === AskedFor::CvAndCoverLetter->value,
        )->once();
    }

    /**
     * A second capture is how a posting the owner already had comes to be
     * wanted or to carry a request for a letter, so the duplicate line says
     * what was asked for too.
     */
    public function test_it_logs_what_a_second_capture_asked_for(): void
    {
        $log = Log::spy();

        $this->capture()->assertCreated();
        $this->capture($this->payload(['asked_for' => 'cv_and_cover_letter']))->assertOk();

        $log->shouldHaveReceived('log')->withArgs(
            fn (string $level, string $message, array $context): bool => $message === 'posting.duplicate'
                && $context['asked_for'] === 'cv_and_cover_letter',
        )->once();
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
