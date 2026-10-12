<?php

namespace Database\Factories;

use App\Models\MasterCvVersion;
use App\Models\Posting;
use App\Models\Revision;
use App\Revisions\RevisionSource;
use App\Revisions\RevisionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            // No test reaches a live model, so this names no real one.
            'model' => 'test-model',
            'prompt_version' => 'v1',
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
