<?php

namespace App\Generation;

use App\Llm\LlmResponse;
use App\MasterCv\Lint\LintReport;
use App\Models\Revision;
use App\Observability\EventLogger;

/**
 * Generation's log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at
 * their call sites and are not renamed casually.
 *
 * Nothing here carries prompt text, job description text, CV content or model
 * output. Sizes, counts, identifiers and outcomes only: a log line that would
 * be embarrassing in a public bug report is a bug.
 */
final readonly class GenerationEvents
{
    public function __construct(private EventLogger $log) {}

    /**
     * A posting has a revision waiting to be generated.
     */
    public function queued(Revision $revision): void
    {
        $this->log->log('posting.queued', [
            'posting_id' => $revision->posting_id,
            'revision_id' => $revision->id,
            'master_cv_version_id' => $revision->master_cv_version_id,
        ]);
    }

    /**
     * An alarm: the system is waiting on the owner, and a silent wait is
     * indistinguishable from a system that has stopped.
     *
     * Counts, not findings. A finding quotes the CV. A null count of problems
     * means the CV never reached its lint, which is what an unreadable file
     * looks like from here.
     */
    public function queueBlocked(int $waiting, ?int $problems): void
    {
        $this->log->warning('queue.blocked', [
            'postings_waiting' => $waiting,
            'problems' => $problems,
        ]);
    }

    /**
     * The sizes are the point. A master CV that arrives truncated is invisible
     * in the output and obvious here.
     */
    public function promptBuilt(
        Revision $revision,
        int $enumCount,
        int $instructionsChars,
        int $masterCvChars,
        int $briefChars,
    ): void {
        $this->log->log('prompt.built', [
            'revision_id' => $revision->id,
            'posting_id' => $revision->posting_id,
            'master_cv_version_id' => $revision->master_cv_version_id,
            'prompt_version' => PromptVersion::CURRENT,
            'enum_count' => $enumCount,
            'instructions_chars' => $instructionsChars,
            'master_cv_chars' => $masterCvChars,
            'brief_chars' => $briefChars,
        ]);
    }

    /**
     * A warning rather than an error: the master CV is the author's to fix,
     * and the process that found the problem carries on with the next
     * revision.
     *
     * Counts, not findings. A finding quotes the CV.
     */
    public function refused(Revision $revision, LintReport $report): void
    {
        $this->log->warning('generation.refused', [
            'revision_id' => $revision->id,
            'master_cv_version_id' => $revision->master_cv_version_id,
            'problems' => count($report->problems()),
            'by_check' => $report->problemsByCheck(),
        ]);
    }

    public function stored(Revision $revision, LlmResponse $response, int $outputChars): void
    {
        $this->log->log('generation.stored', [
            'revision_id' => $revision->id,
            // Both names, each under the name of its job: the cost was
            // computed from the requested model, and the reported one is the
            // build that served the request and the one the revision stores.
            'requested_model' => $response->requestedModel,
            'reported_model' => $response->reportedModel,
            'prompt_version' => $revision->prompt_version,
            'output_chars' => $outputChars,
            'cost_micros' => $response->costMicros,
            ...$response->usage->logContext(),
        ]);
    }

    /**
     * @param  string  $reason  A fixed phrase. Never a quote from the output.
     */
    public function unusable(Revision $revision, LlmResponse $response, string $reason): void
    {
        $this->log->warning('generation.unusable', [
            'revision_id' => $revision->id,
            'requested_model' => $response->requestedModel,
            'reported_model' => $response->reportedModel,
            'stop_reason' => $response->stopReason,
            'output_chars' => mb_strlen($response->text),
            'reason' => $reason,
        ]);
    }
}
