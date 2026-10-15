<?php

namespace App\Extraction;

use App\Llm\LlmResponse;
use App\Models\Posting;
use App\Observability\EventLogger;
use Throwable;

/**
 * Extraction's log lines, in one place.
 *
 * Event names are part of the interface, so they live here rather than at their
 * call sites and are not renamed casually.
 *
 * Nothing here carries the page's text, the description or the model's prose.
 * Which fields were filled, from which source, and what each cost: a log line
 * that would be embarrassing in a public bug report is a bug.
 */
final readonly class ExtractionEvents
{
    public function __construct(private EventLogger $log) {}

    public function extracted(
        Posting $posting,
        LlmResponse $response,
        ExtractedFields $fields,
    ): void {
        $this->log->log('posting.extracted', [
            'posting_id' => $posting->id,
            'prompt_version' => PromptVersion::CURRENT,
            // Both names, each under the name of its job: the cost was
            // computed from the requested model, and the reported one is the
            // build that served the call.
            'requested_model' => $response->requestedModel,
            'reported_model' => $response->reportedModel,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cost_micros' => $response->costMicros,
            // Which fields were filled and from which source, with a null
            // where neither filled one. Every field appears, so a line's
            // shape does not depend on what a page happened to say.
            'fields' => $fields->sources,
            // The two sources compared, without either value: a field the page
            // stated and the model read differently is the case worth seeing.
            'json_ld_disagreed' => $fields->disagreements,
        ]);
    }

    /**
     * A warning rather than an error: the posting is listed, markable as
     * wanted, and the queue will try again. Nothing has been lost.
     */
    public function failed(Posting $posting, Throwable $failure, int $attempts): void
    {
        $this->log->warning('posting.extraction_failed', [
            'posting_id' => $posting->id,
            'failure' => ExtractionFailureClass::of($failure),
            'attempts' => $attempts,
        ]);
    }
}
