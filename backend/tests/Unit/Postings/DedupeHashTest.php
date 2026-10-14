<?php

namespace Tests\Unit\Postings;

use App\Postings\DedupeHash;
use App\Postings\PostingCapture;
use PHPUnit\Framework\TestCase;

class DedupeHashTest extends TestCase
{
    public function test_the_same_page_hashes_the_same_however_it_is_laid_out(): void
    {
        $this->assertSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(
                pageText: "  We are looking for\n\n A   PHP   ENGINEER.  ",
            )),
        );
    }

    public function test_the_url_is_not_part_of_it(): void
    {
        $this->assertSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(url: 'https://another-board.example.com/ad/992')),
        );
    }

    /**
     * The hash is over the page and over nothing a model produced. A hash
     * derived from model output would make a retried capture hash differently
     * from the first attempt, and capture would stop being idempotent — which
     * is the property the extension's retry queue rests on.
     */
    public function test_nothing_but_the_page_text_is_part_of_it(): void
    {
        $hash = DedupeHash::for($this->capture());

        $this->assertSame($hash, DedupeHash::for($this->capture(hasHiddenText: true)));
        $this->assertSame($hash, DedupeHash::for($this->capture(sourceSite: 'another-board.example.com')));
        $this->assertSame($hash, DedupeHash::for($this->capture(jsonLd: [
            '@type' => 'JobPosting',
            'title' => 'Senior PHP Engineer',
        ])));
    }

    public function test_a_different_page_is_a_different_posting(): void
    {
        $this->assertNotSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(pageText: 'We are looking for a Go engineer.')),
        );
    }

    /**
     * @param  array<string, mixed>|null  $jsonLd
     */
    private function capture(
        string $url = 'https://jobs.example.com/vacancy/7781',
        string $sourceSite = 'jobs.example.com',
        string $pageText = 'We are looking for a PHP engineer.',
        bool $hasHiddenText = false,
        ?array $jsonLd = null,
    ): PostingCapture {
        return new PostingCapture(
            url: $url,
            sourceSite: $sourceSite,
            pageText: $pageText,
            hasHiddenText: $hasHiddenText,
            jsonLd: $jsonLd,
        );
    }
}
