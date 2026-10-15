<?php

namespace Database\Factories;

use App\Generation\PromptVersion;
use App\Models\MasterCvVersion;
use App\Models\Posting;
use App\Models\Revision;
use App\Revisions\RevisionSource;
use App\Revisions\RevisionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;

/**
 * @extends Factory<Revision>
 */
class RevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'posting_id' => Posting::factory(),
            'parent_revision_id' => null,
            'master_cv_version_id' => MasterCvVersion::factory(),
            'source' => RevisionSource::Generated,
            'instructions' => null,
            // The model a revision records is one the system could price: a
            // revision waiting for a result records what it asked for, and a
            // collected result is priced from that name. A fiction would have
            // no configured rate. No test reaches a live model regardless —
            // the fake client is what `LlmClient` is bound to here.
            'model' => Config::string('cv.llm.models.generation'),
            'prompt_version' => PromptVersion::CURRENT,
            'status' => RevisionStatus::Pending,
            'attempts' => 0,
        ];
    }

    public function status(RevisionStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function childOf(Revision $parent): static
    {
        return $this->state([
            'posting_id' => $parent->posting_id,
            'parent_revision_id' => $parent->getKey(),
            'master_cv_version_id' => $parent->master_cv_version_id,
            'source' => RevisionSource::Regenerated,
        ]);
    }
}
