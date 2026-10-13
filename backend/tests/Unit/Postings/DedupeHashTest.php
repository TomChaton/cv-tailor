<?php

namespace Tests\Unit\Postings;

use App\Postings\DedupeHash;
use App\Postings\PostingCapture;
use PHPUnit\Framework\TestCase;

class DedupeHashTest extends TestCase
{
    public function test_the_same_posting_hashes_the_same_however_it_is_laid_out(): void
    {
        $this->assertSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(
                title: 'SENIOR  PHP   ENGINEER',
                extractedText: "  We are looking for\n\n a PHP engineer.  ",
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

    public function test_the_html_is_not_part_of_it(): void
    {
        $this->assertSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(rawHtml: '<section><p>We are looking for a PHP engineer.</p></section>')),
        );
    }

    public function test_a_different_company_is_a_different_posting(): void
    {
        $this->assertNotSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(company: 'Another Example Ltd')),
        );
    }

    public function test_a_different_description_is_a_different_posting(): void
    {
        $this->assertNotSame(
            DedupeHash::for($this->capture()),
            DedupeHash::for($this->capture(extractedText: 'We are looking for a Go engineer.')),
        );
    }

    /**
     * The separator between the parts cannot appear in any of them, so no
     * wording can shuffle words across the boundary and collide.
     */
    public function test_the_parts_cannot_run_into_one_another(): void
    {
        $this->assertNotSame(
            DedupeHash::for($this->capture(title: 'Senior PHP', company: 'Engineer Example Ltd')),
            DedupeHash::for($this->capture(title: 'Senior PHP Engineer', company: 'Example Ltd')),
        );
    }

    private function capture(
        string $url = 'https://jobs.example.com/vacancy/7781',
        ?string $title = 'Senior PHP Engineer',
        ?string $company = 'Example Ltd',
        string $extractedText = 'We are looking for a PHP engineer.',
        string $rawHtml = '<div><p>We are looking for a PHP engineer.</p></div>',
    ): PostingCapture {
        return new PostingCapture(
            url: $url,
            sourceSite: 'jobs.example.com',
            extractedText: $extractedText,
            rawHtml: $rawHtml,
            hasHiddenText: false,
            title: $title,
            company: $company,
        );
    }
}
