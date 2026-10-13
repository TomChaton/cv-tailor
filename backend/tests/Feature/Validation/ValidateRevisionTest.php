<?php

namespace Tests\Feature\Validation;

use App\Models\MasterCvVersion;
use App\Models\Revision;
use App\Observability\EventLogger;
use App\Revisions\RevisionSource;
use App\Revisions\RevisionStatus;
use App\Validation\RevisionCannotBeValidated;
use App\Validation\ValidateRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;
use Tests\TestCase;

class ValidateRevisionTest extends TestCase
{
    use MasterCvFixtures;
    use RefreshDatabase;
    use SelectionFixtures;

    /**
     * `validated` means validation has run, not that it passed. The findings
     * say whether it passed.
     */
    public function test_a_clean_revision_is_validated_with_an_empty_list_of_findings(): void
    {
        $revision = $this->revision($this->selection());

        $this->validate()->handle($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Validated, $stored->status);
        $this->assertSame([], $stored->validation_json);
    }

    /**
     * An empty list and a null are different facts about a revision: one was
     * checked and one never was.
     */
    public function test_a_revision_that_has_not_been_validated_stores_null(): void
    {
        $revision = $this->revision($this->selection());

        $this->assertNull($revision->validation_json);

        $this->validate()->handle($revision);

        $this->assertSame([], $revision->fresh()?->validation_json);
    }

    /**
     * `failed` is for a revision that could not be produced, not one produced
     * wrongly. The owner regenerates from this one, which needs it intact.
     */
    public function test_a_blocked_revision_stays_validated(): void
    {
        $revision = $this->revision($this->selectionWithSummary(
            'Backend engineer running dispatch services on Kubernetes.',
        ));

        $this->validate()->handle($revision);

        $stored = $revision->fresh();

        $this->assertNotNull($stored);
        $this->assertSame(RevisionStatus::Validated, $stored->status);
        $this->assertNotSame([], $stored->validation_json);
    }

    public function test_the_stored_finding_records_the_check_the_severity_the_field_and_a_message(): void
    {
        $revision = $this->revision($this->selectionWithItems('harlequin-transit', [
            $this->reworded('harlequin-transit.4', 'Designed and built the dispatch API.', []),
        ]));

        $this->validate()->handle($revision);

        $findings = $this->storedFindings($revision);

        $this->assertSame('rewordings_carry_support', $findings[0]['check']);
        $this->assertSame('blocking', $findings[0]['severity']);
        $this->assertSame('roles[0].items[0].supports', $findings[0]['field']);
        $this->assertSame(['harlequin-transit.4'], $findings[0]['ids']);
        $this->assertIsString($findings[0]['message']);
        $this->assertNotSame('', $findings[0]['message']);
    }

    /**
     * The guarantee cannot depend on where the content came from: a manual
     * edit is words the owner typed, which is no better evidenced than words a
     * model wrote.
     */
    public function test_a_manual_edit_runs_every_check(): void
    {
        $selection = $this->selectionWithItems('northgate-tickets', [
            // One item that breaks a different check each: a missing ID, a
            // transferred technology, a version the role never ran, and a
            // forbidden term.
            $this->verbatim('northgate-tickets.99'),
            $this->reworded(
                'northgate-tickets.3',
                'Moved venue search onto Elasticsearch 8 behind Terraform, with Kafka alongside.',
                ['northgate-tickets.3'],
            ),
            $this->verbatim('fathom-index.1'),
        ]);
        $selection['roles'] = $this->demotedAndPromoted($selection);

        $revision = $this->revision($selection, RevisionSource::ManualEdit);

        $this->validate()->handle($revision);

        $raised = [];

        foreach ($this->storedFindings($revision) as $finding) {
            $check = $finding['check'];

            if (is_string($check)) {
                $raised[$check] = true;
            }
        }

        $raised = array_keys($raised);
        sort($raised);

        $this->assertSame([
            'do_not_claim',
            'ids_resolve',
            'independent_projects_stay_independent',
            'promotion_explained',
            'role_mode_not_demoted',
            'technology_to_role',
            'versions',
        ], $raised);
    }

    public function test_it_logs_the_counts_and_never_the_findings(): void
    {
        $revision = $this->revision($this->selection());

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('revision.validated', Mockery::on(function (array $context) use ($revision): bool {
                $this->assertSame($revision->id, $context['revision_id']);
                $this->assertSame($revision->master_cv_version_id, $context['master_cv_version_id']);
                $this->assertSame(0, $context['findings']);
                $this->assertSame(0, $context['blocking']);
                $this->assertSame(0, $context['advisory']);
                $this->assertSame([], $context['by_check']);

                return true;
            }));
        $logger->shouldNotReceive('warning');

        $this->instance(EventLogger::class, $logger);

        $this->validate()->handle($revision);
    }

    public function test_it_warns_when_a_check_blocks(): void
    {
        $revision = $this->revision($this->selectionWithSummary(
            'Backend engineer running dispatch services on Kubernetes.',
        ));

        /** @var EventLogger&MockInterface $logger */
        $logger = Mockery::mock(EventLogger::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('revision.validated', Mockery::on(function (array $context): bool {
                $this->assertSame(1, $context['blocking']);
                $this->assertSame(['do_not_claim' => 1], $context['by_check']);

                return true;
            }));
        $logger->shouldReceive('warning')
            ->once()
            ->with('revision.validation_blocked', Mockery::on(function (array $context): bool {
                $this->assertSame(['do_not_claim'], $context['checks']);

                return true;
            }));

        $this->instance(EventLogger::class, $logger);

        $this->validate()->handle($revision);
    }

    /**
     * An empty report means "checked and clean", which is the one thing a
     * revision with no output is not.
     */
    public function test_it_refuses_a_revision_with_nothing_to_validate(): void
    {
        $revision = Revision::factory()->create([
            'master_cv_version_id' => MasterCvVersion::factory()->content($this->fixture()),
        ]);

        $this->expectException(RevisionCannotBeValidated::class);

        try {
            $this->validate()->handle($revision);
        } finally {
            $this->assertNull($revision->fresh()?->validation_json);
            $this->assertSame(RevisionStatus::Pending, $revision->fresh()?->status);
        }
    }

    /**
     * A demotion and an unexplained promotion, so the two mode checks both
     * have something to say.
     *
     * @param  array<string, mixed>  $selection
     * @return list<array<string, mixed>>
     */
    private function demotedAndPromoted(array $selection): array
    {
        /** @var list<array<string, mixed>> $roles */
        $roles = $selection['roles'];
        $roles[2]['mode'] = 'omitted';
        $roles[3]['mode'] = 'full';

        return $roles;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedFindings(Revision $revision): array
    {
        /** @var list<array<string, mixed>> $findings */
        $findings = $revision->fresh()->validation_json ?? [];

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function revision(array $output, RevisionSource $source = RevisionSource::Generated): Revision
    {
        return Revision::factory()->create([
            'master_cv_version_id' => MasterCvVersion::factory()->content($this->fixture()),
            'source' => $source,
            'status' => RevisionStatus::Generated,
            'output_json' => $output,
        ]);
    }

    private function validate(): ValidateRevision
    {
        return $this->app->make(ValidateRevision::class);
    }
}
