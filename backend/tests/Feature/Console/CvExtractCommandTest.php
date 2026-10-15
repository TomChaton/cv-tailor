<?php

namespace Tests\Feature\Console;

use App\Jobs\ExtractPostingFields;
use App\Llm\Testing\FakeLlmClient;
use App\Models\Posting;
use App\Postings\PostingStatus;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CvExtractCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A posting whose extraction never answered is asked about again without
     * being captured again. Whatever stopped it — a key that was not set, a
     * model with no rate, a provider that was down — is configuration, and
     * this is how a fixed configuration reaches everything that was waiting.
     */
    public function test_a_posting_extraction_never_answered_is_asked_about_again(): void
    {
        $this->app->make(FakeLlmClient::class)->willAnswerWith('extraction');

        $posting = Posting::factory()->awaitingExtraction()->create();

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:extract'));
        $this->assertStringContainsString('1 posting queued for extraction.', Artisan::output());

        $extracted = $posting->fresh();

        $this->assertNotNull($extracted);
        $this->assertSame('Senior PHP Engineer', $extracted->title);
        $this->assertNotNull($extracted->extracted_at);
    }

    /**
     * `extracted_at` is the guard, as it is for a retried queue job: a second
     * reading of the same page would be paid for twice to reach the same
     * answer. A page extraction read and found no job in has been answered
     * too — the answer to one of those is to discard the posting.
     */
    public function test_a_posting_extraction_has_answered_is_left_alone(): void
    {
        Queue::fake();

        Posting::factory()->create();
        Posting::factory()->create(['description' => null]);

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:extract'));
        $this->assertStringContainsString('Nothing waiting.', Artisan::output());

        Queue::assertNothingPushed();
    }

    public function test_a_discarded_posting_is_never_read_again(): void
    {
        Queue::fake();

        $discarded = Posting::factory()
            ->awaitingExtraction()
            ->status(PostingStatus::Discarded)
            ->create();
        $waiting = Posting::factory()->awaitingExtraction()->create();

        $this->assertSame(Command::SUCCESS, Artisan::call('cv:extract'));

        Queue::assertPushed(
            ExtractPostingFields::class,
            fn (ExtractPostingFields $job): bool => $job->posting->is($waiting),
        );
        Queue::assertNotPushed(
            ExtractPostingFields::class,
            fn (ExtractPostingFields $job): bool => $job->posting->is($discarded),
        );
    }
}
