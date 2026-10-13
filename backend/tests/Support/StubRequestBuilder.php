<?php

namespace Tests\Support;

use App\Batching\BatchRequestBuilder;
use App\Generation\GenerationIsRefused;
use App\Llm\BatchRequest;
use App\Llm\LlmPurpose;
use App\Llm\LlmRequest;
use App\MasterCv\Lint\LintReport;
use App\Models\Revision;

/**
 * A request builder that builds no prompt.
 *
 * The batch lifecycle's own tests are about states and results, not about what
 * is in a request, and the real builder needs a master CV that lints for every
 * revision it is handed. The contract between the two is asserted where it is
 * bound, not here.
 */
class StubRequestBuilder implements BatchRequestBuilder
{
    /** @var list<int> */
    public array $refuse = [];

    /** @var list<int> */
    public array $built = [];

    public function batchRequestFor(Revision $revision): BatchRequest
    {
        if (in_array($revision->id, $this->refuse, strict: true)) {
            throw GenerationIsRefused::masterCvFailedLint($revision, new LintReport([]));
        }

        $this->built[] = $revision->id;

        return new BatchRequest(
            (string) $revision->id,
            new LlmRequest(
                purpose: LlmPurpose::Generation,
                system: [],
                prompt: 'a job',
                maxTokens: 100,
            ),
        );
    }

    public function refusing(int ...$revisionIds): self
    {
        $this->refuse = array_values($revisionIds);

        return $this;
    }
}
