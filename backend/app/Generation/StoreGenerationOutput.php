<?php

namespace App\Generation;

use App\Llm\LlmResponse;
use App\Models\Revision;
use App\Revisions\RevisionStatus;

/**
 * Records what the model returned for a revision.
 *
 * The selection is stored as it came back, unresolved and unvalidated:
 * resolving it against the master CV and checking it are separate steps with
 * their own columns, and keeping the raw selection is what lets a validation
 * change be re-run over revisions that already exist.
 *
 * `prompt_version` is not written here. It is recorded when the request is
 * built, which is when it becomes true of the revision.
 */
class StoreGenerationOutput
{
    public function __construct(private readonly GenerationEvents $events) {}

    public function handle(Revision $revision, LlmResponse $response): Revision
    {
        $output = $this->decode($revision, $response);

        $revision->fill([
            'model' => $response->model,
            'output_json' => $output,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cache_read_tokens' => $response->usage->cacheReadTokens,
            'cache_write_tokens' => $response->usage->cacheWriteTokens,
            'cost_micros' => $response->costMicros,
            'status' => RevisionStatus::Generated,
        ])->save();

        $this->events->stored($revision, $response, mb_strlen($response->text));

        return $revision;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Revision $revision, LlmResponse $response): array
    {
        $decoded = json_decode($response->text, associative: true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->events->unusable($revision, $response, 'the response is not a JSON object');

            throw GenerationOutputIsUnusable::notAnObject($revision);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
