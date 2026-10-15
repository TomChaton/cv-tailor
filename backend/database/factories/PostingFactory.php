<?php

namespace Database\Factories;

use App\Models\Posting;
use App\Postings\PostingStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Posting>
 */
class PostingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'url' => fake()->url(),
            'source_site' => fake()->domainName(),
            'title' => fake()->jobTitle(),
            'company' => fake()->company(),
            'location' => fake()->city(),
            'salary_text' => null,
            'page_text' => $text,
            'json_ld' => null,
            'description' => $text,
            'extracted_at' => now(),
            'has_hidden_text' => false,
            'cover_letter_requested' => false,
            'dedupe_hash' => hash('sha256', Str::random(32)),
            'status' => PostingStatus::Captured,
        ];
    }

    /**
     * A posting as capture leaves it: the page's text, and nothing read out of
     * it yet. Extraction is what fills the rest, so the default is a posting it
     * has already run on — most tests want a posting that says something.
     */
    public function awaitingExtraction(): static
    {
        return $this->state([
            'title' => null,
            'company' => null,
            'location' => null,
            'salary_text' => null,
            'description' => null,
            'extracted_at' => null,
        ]);
    }

    public function status(PostingStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * A posting the owner has asked for a CV for, which is the one state
     * `cv:queue` sweeps. The default is `captured`, because that is what a
     * capture produces and nothing but the owner moves it off.
     */
    public function wanted(): static
    {
        return $this->status(PostingStatus::Wanted);
    }

    /**
     * A listing carrying text aimed at AI tools rather than at a reader. The
     * review UI warns about these, so something has to be able to make one.
     */
    public function withHiddenText(): static
    {
        return $this->state(['has_hidden_text' => true]);
    }

    /**
     * A posting the owner asked for a cover letter for. Nothing writes one;
     * the column is the record of the request.
     */
    public function withCoverLetterRequested(): static
    {
        return $this->state(['cover_letter_requested' => true]);
    }
}
