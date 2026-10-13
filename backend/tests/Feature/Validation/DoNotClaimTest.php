<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Checks\DoNotClaim;
use App\Validation\Severity;

class DoNotClaimTest extends CheckTestCase
{
    public function test_a_selection_claiming_nothing_on_the_list_passes(): void
    {
        $this->assertPasses($this->selection());
    }

    public function test_a_forbidden_term_in_the_summary_fails(): void
    {
        $findings = $this->assertFails(
            $this->selectionWithSummary('Backend engineer who runs dispatch services on Kubernetes.'),
        );

        $this->assertSame(Severity::Blocking, $findings[0]->severity);
        $this->assertSame('summary.text', $findings[0]->field);
        $this->assertStringContainsString('Kubernetes', $findings[0]->message);
    }

    public function test_a_forbidden_term_in_a_rewording_fails(): void
    {
        $findings = $this->assertFails($this->selectionWithItems('northgate-tickets', [
            $this->reworded(
                'northgate-tickets.1',
                'Built the seat-reservation service on Symfony and Kafka.',
                ['northgate-tickets.1'],
            ),
        ]));

        $this->assertStringContainsString('Kafka', $findings[0]->message);
    }

    public function test_a_forbidden_term_in_the_headline_fails(): void
    {
        $this->assertFails($this->selection(['headline' => 'SRE']));
    }

    /**
     * The section lists the terms in bold and then says what *is* recorded.
     * Reading the explanation as terms would block a CV for claiming
     * RabbitMQ, which the master CV supports.
     */
    public function test_the_explanation_after_a_term_is_not_itself_a_term(): void
    {
        $this->assertPasses($this->selectionWithItems('northgate-tickets', [
            $this->reworded(
                'northgate-tickets.1',
                'Built the seat-reservation service on Symfony and RabbitMQ.',
                ['northgate-tickets.1'],
            ),
        ]));
    }

    /**
     * A parenthetical sitting directly after the bold lead-in carries the
     * list, and those are the terms worth catching.
     */
    public function test_a_term_from_a_bracketed_list_fails(): void
    {
        $findings = $this->assertFails(
            $this->selectionWithSummary('Backend engineer who instruments services with Datadog.'),
        );

        $this->assertStringContainsString('Datadog', $findings[0]->message);
    }

    /**
     * The master CV's rules ask for an unsupported requirement to be reported
     * as a gap. A check that blocked on the report would fire on correct
     * behaviour, and would fire on nearly every job.
     */
    public function test_reporting_a_requirement_as_a_gap_is_not_a_claim(): void
    {
        $this->assertPasses($this->selection([
            'jd_analysis' => [$this->requirement('Kubernetes in production', 'gap', [])],
            'review_notes' => $this->reviewNotes(gaps: ['The job asks for Kubernetes and Kafka.']),
        ]));
    }

    /**
     * "Rust in an employed setting" is the term, not "Rust": the master CV
     * supports Rust in an independent project and says so.
     */
    public function test_a_term_qualified_by_its_setting_does_not_forbid_the_bare_word(): void
    {
        $this->assertPasses($this->selectionWithItems('fathom-index', [
            $this->reworded(
                'fathom-index.1',
                'Rust indexer writing an inverted index to SQLite.',
                ['fathom-index.1'],
            ),
        ], 'independent_projects'));
    }

    protected function check(): Check
    {
        return $this->app->make(DoNotClaim::class);
    }
}
