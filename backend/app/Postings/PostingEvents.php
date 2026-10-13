<?php

namespace App\Postings;

use App\Models\Posting;
use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * The postings' log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at their
 * call sites and are not renamed casually.
 *
 * Nothing here carries the page's text, its HTML or its URL. The posting row
 * holds all three, and a log line that would be embarrassing in a public bug
 * report is a bug.
 */
final readonly class PostingEvents
{
    public function __construct(private EventLogger $log) {}

    /**
     * The sizes are the point. A capture whose extraction silently returned a
     * fragment of the page is invisible in the posting and obvious here.
     */
    public function captured(Posting $posting, PostingCapture $capture): void
    {
        $this->log->log('posting.captured', [
            'posting_id' => $posting->id,
            'source_site' => $capture->sourceSite,
            'dedupe_hash' => $posting->dedupe_hash,
            'has_hidden_text' => $capture->hasHiddenText,
            'raw_html_bytes' => strlen($capture->rawHtml),
            'extracted_text_chars' => mb_strlen($capture->extractedText),
        ]);
    }

    /**
     * Not a failure: capture is idempotent, and a retry or a second board
     * finding the same role is the ordinary case.
     */
    public function duplicate(Posting $posting, PostingCapture $capture): void
    {
        $this->log->log('posting.duplicate', [
            'posting_id' => $posting->id,
            'source_site' => $capture->sourceSite,
        ]);
    }

    /**
     * A posting whose current revision is a CV the owner can send.
     */
    public function ready(Revision $revision): void
    {
        $this->log->log('posting.ready', [
            'posting_id' => $revision->posting_id,
            'revision_id' => $revision->id,
        ]);
    }

    /**
     * A warning: a rejected capture is a posting the owner thinks they have.
     *
     * Field names only. The values are the posting's own text, and the body
     * that carried them is gone.
     *
     * @param  list<string>  $fields
     */
    public function rejected(array $fields): void
    {
        $this->log->warning('posting.rejected', ['fields' => $fields]);
    }
}
