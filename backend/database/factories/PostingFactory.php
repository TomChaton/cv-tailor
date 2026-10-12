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
            'raw_html' => '<div>'.$text.'</div>',
            'extracted_text' => $text,
            'has_hidden_text' => false,
            'dedupe_hash' => hash('sha256', Str::random(32)),
            'status' => PostingStatus::Captured,
        ];
    }

    public function status(PostingStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * A listing carrying text aimed at AI tools rather than at a reader. The
     * review UI warns about these, so something has to be able to make one.
     */
    public function withHiddenText(): static
    {
        return $this->state(['has_hidden_text' => true]);
    }
}
