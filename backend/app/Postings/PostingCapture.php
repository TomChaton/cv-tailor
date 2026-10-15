<?php

namespace App\Postings;

/**
 * One capture, as the extension sent it.
 *
 * Capture carries the raw material and no interpretation of it: the page's
 * visible text and whatever structured data the page published about itself.
 * Which part of that text is the job description is a judgement, and it is
 * made here rather than in the browser — a judgement made at capture time
 * cannot be revisited, because what was not stored cannot be re-read.
 *
 * `askedFor` is the one thing here the page did not say: what the owner chose
 * when they captured it. A capture that omits it asked to keep the posting,
 * which is what capture has always done.
 */
final readonly class PostingCapture
{
    /**
     * @param  array<string, mixed>|null  $jsonLd  The page's `JobPosting`
     *                                             object, uninterpreted.
     */
    public function __construct(
        public string $url,
        public string $sourceSite,
        public string $pageText,
        public bool $hasHiddenText,
        public ?array $jsonLd = null,
        public AskedFor $askedFor = AskedFor::Save,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'url' => $this->url,
            'source_site' => $this->sourceSite,
            'page_text' => $this->pageText,
            'json_ld' => $this->jsonLd,
            'has_hidden_text' => $this->hasHiddenText,
        ];
    }
}
