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
 * Nothing here carries the page's text or its URL. The posting row holds both,
 * and a log line that would be embarrassing in a public bug report is a bug.
 */
final readonly class PostingEvents
{
    public function __construct(private EventLogger $log) {}

    /**
     * The size is the point. A capture whose page read back as a fragment is
     * invisible in the posting and obvious here.
     *
     * `asked_for` is which of the popup's actions the owner clicked. A capture
     * that asked for a CV also emits `posting.wanted`; this is the only line
     * that says a cover letter was asked for, because nothing writes one yet
     * and an event for nothing happening is noise.
     */
    public function captured(Posting $posting, PostingCapture $capture): void
    {
        $this->log->log('posting.captured', [
            'posting_id' => $posting->id,
            'asked_for' => $capture->askedFor->value,
            'source_site' => $capture->sourceSite,
            'dedupe_hash' => $posting->dedupe_hash,
            'has_hidden_text' => $capture->hasHiddenText,
            'page_text_chars' => mb_strlen($capture->pageText),
            // Whether the page states its own facts decides whether extraction
            // has anything to defer to, so it belongs beside the capture.
            'has_json_ld' => $capture->jsonLd !== null,
        ]);
    }

    /**
     * Not a failure: capture is idempotent, and a retry or a second reading of
     * the same page is the ordinary case.
     *
     * It carries what was asked for too, because a second capture is how a
     * posting the owner already had comes to be wanted or to carry a request
     * for a cover letter, and this is the only line that reports one.
     */
    public function duplicate(Posting $posting, PostingCapture $capture): void
    {
        $this->log->log('posting.duplicate', [
            'posting_id' => $posting->id,
            'asked_for' => $capture->askedFor->value,
            'source_site' => $capture->sourceSite,
        ]);
    }

    /**
     * The owner has decided this posting is worth a CV.
     *
     * The line that says money is about to be spent: nothing on a schedule
     * writes it, so every generation the system pays for can be traced back
     * to one of these.
     */
    public function wanted(Posting $posting): void
    {
        $this->log->log('posting.wanted', [
            'posting_id' => $posting->id,
        ]);
    }

    /**
     * A posting the owner is done with.
     *
     * `from` is the status it left. A posting discarded on sight and one
     * discarded after its CV was read are the same decision taken about very
     * different things, and the status it was in is the only thing that tells
     * a reader which happened.
     *
     * `revisions_abandoned` is what the decision stopped. A discard that
     * abandoned generation is a different event in kind from one that
     * abandoned none, and the owner is paying for the difference.
     */
    public function discarded(Posting $posting, PostingStatus $from, int $revisionsAbandoned): void
    {
        $this->log->log('posting.discarded', [
            'posting_id' => $posting->id,
            'from' => $from->value,
            'revisions_abandoned' => $revisionsAbandoned,
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
     * Field names only. The values are the page's own text, and the body
     * that carried them is gone.
     *
     * @param  list<string>  $fields
     */
    public function rejected(array $fields): void
    {
        $this->log->warning('posting.rejected', ['fields' => $fields]);
    }
}
