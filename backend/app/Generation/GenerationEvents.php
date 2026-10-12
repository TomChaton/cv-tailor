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
            'model' => $response->model,
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
            'model' => $response->model,
            'stop_reason' => $response->stopReason,
            'output_chars' => mb_strlen($response->text),
            'reason' => $reason,
        ]);
    }
}
