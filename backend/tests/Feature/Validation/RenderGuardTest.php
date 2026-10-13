<?php

namespace Tests\Feature\Validation;

use App\Models\Revision;
use App\Revisions\RevisionStatus;
use App\Validation\RenderGuard;
use App\Validation\RevisionIsNotRenderable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenderGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_validated_revision_with_no_findings_may_be_rendered(): void
    {
        $revision = Revision::factory()->create([
            'status' => RevisionStatus::Validated,
            'validation_json' => [],
        ]);

        $this->assertTrue($this->guard()->allows($revision));
        $this->guard()->ensure($revision);
    }

    public function test_an_advisory_finding_does_not_stop_a_render(): void
    {
        $revision = Revision::factory()->create([
            'status' => RevisionStatus::Validated,
            'validation_json' => [[
                'check' => 'word_budget',
                'severity' => 'advisory',
                'field' => null,
                'ids' => [],
                'message' => 'Longer than the target.',
            ]],
        ]);

        $this->assertTrue($this->guard()->allows($revision));
    }

    public function test_a_blocking_finding_stops_a_render(): void
    {
        $revision = Revision::factory()->create([
            'status' => RevisionStatus::Validated,
            'validation_json' => [[
                'check' => 'do_not_claim',
                'severity' => 'blocking',
                'field' => 'summary.text',
                'ids' => [],
                'message' => 'Kubernetes is in the master CV\'s "Do not claim" section.',
            ]],
        ]);

        $this->assertFalse($this->guard()->allows($revision));

        $this->expectException(RevisionIsNotRenderable::class);
        $this->expectExceptionMessageMatches('/do_not_claim/');

        $this->guard()->ensure($revision);
    }

    /**
     * A null `validation_json` is not an empty one, and "nobody checked" is
     * not a reason to render.
     */
    public function test_a_revision_that_has_never_been_validated_is_refused(): void
    {
        $revision = Revision::factory()->create([
            'status' => RevisionStatus::Validated,
            'validation_json' => null,
        ]);

        $this->assertFalse($this->guard()->allows($revision));

        $this->expectException(RevisionIsNotRenderable::class);

        $this->guard()->ensure($revision);
    }

    public function test_a_revision_that_is_still_generating_is_refused(): void
    {
        $revision = Revision::factory()->create([
            'status' => RevisionStatus::Generated,
            'validation_json' => [],
        ]);

        $this->assertFalse($this->guard()->allows($revision));

        $this->expectException(RevisionIsNotRenderable::class);

        $this->guard()->ensure($revision);
    }

    /**
     * A severity nothing can read is treated as blocking. A finding nobody can
     * classify must not be the reason a CV is let through.
     */
    public function test_an_unreadable_finding_blocks(): void
    {
        $revision = Revision::factory()->create([
            'status' => RevisionStatus::Validated,
            'validation_json' => [['check' => 'something', 'severity' => 'who knows']],
        ]);

        $this->assertFalse($this->guard()->allows($revision));
    }

    private function guard(): RenderGuard
    {
        return $this->app->make(RenderGuard::class);
    }
}
