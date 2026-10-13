<?php

namespace App\Postings;

/**
 * One capture, as the extension sent it.
 *
 * Capture carries the raw material and no interpretation of it: the page's
 * text, the container's HTML, and the metadata the page itself exposed.
 * Anything the backend can work out later, it works out later, so better
 * extraction can be re-run over a posting captured long ago.
 */
final readonly class PostingCapture
{
    public function __construct(
        public string $url,
        public string $sourceSite,
        public string $extractedText,
        public string $rawHtml,
        public bool $hasHiddenText,
        public ?string $title = null,
        public ?string $company = null,
        public ?string $location = null,
        public ?string $salaryText = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'url' => $this->url,
            'source_site' => $this->sourceSite,
            'title' => $this->title,
            'company' => $this->company,
            'location' => $this->location,
            'salary_text' => $this->salaryText,
            'raw_html' => $this->rawHtml,
            'extracted_text' => $this->extractedText,
            'has_hidden_text' => $this->hasHiddenText,
        ];
    }
}
