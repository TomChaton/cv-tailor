<?php

namespace Tests\Unit\MasterCv;

use App\MasterCv\Lint\Checks\BlockLabelsAreRecognised;
use App\MasterCv\Lint\Checks\IdsArePresentAndUnique;
use App\MasterCv\Lint\Checks\RecognisedSectionsPresent;
use App\MasterCv\Lint\Checks\SkillNamesAreDistinct;
use App\MasterCv\Lint\Checks\SkillsIndexNamesRealRoles;
use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\LintReport;
use App\MasterCv\Lint\MasterCvLinter;
use App\MasterCv\Lint\Remedy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class MasterCvLinterTest extends TestCase
{
    use MasterCvFixtures;

    public function test_the_fictional_cv_is_fit_to_generate_from(): void
    {
        $report = $this->lint();

        $this->assertTrue($report->passes(), $this->describe($report));
        $this->assertSame([], $report->problemsByCheck());
    }

    public function test_a_minimal_cv_is_fit_to_generate_from(): void
    {
        $report = $this->lint('master-cv/minimal.md');

        $this->assertTrue($report->passes(), $this->describe($report));
    }

    public function test_a_cv_of_labelled_and_wrapped_bullets_is_fit_to_generate_from(): void
    {
        $report = $this->lint('master-cv/labelled-and-wrapped.md');

        $this->assertTrue($report->passes(), $this->describe($report));
    }

    /**
     * A report against a line whose ID is further down the bullet sends the
     * author to edit a line that is already right, so the line named is the
     * one the bullet starts on and the ID is looked for across the whole of it.
     */
    public function test_a_missing_id_is_reported_against_the_line_the_bullet_starts_on(): void
    {
        $cv = $this->parser()->parse(str_replace(
            ' {#wrenbury-freight.2}',
            '',
            $this->fixture('master-cv/labelled-and-wrapped.md'),
        ));

        $report = $this->linter()->lint($cv);

        $this->assertSame(['ids' => 1], $report->problemsByCheck());
        $this->assertSame(48, $report->problems()[0]->line);
    }

    /**
     * Each broken variant differs from the minimal CV by one line, so each
     * raises exactly one problem and names the check that found it.
     */
    #[DataProvider('brokenVariants')]
    public function test_it_names_the_line_and_the_problem(string $fixture, string $check, int $line): void
    {
        $report = $this->lint('master-cv/'.$fixture);

        $this->assertFalse($report->passes());
        $this->assertSame([$check => 1], $report->problemsByCheck());
        $this->assertSame($line, $report->problems()[0]->line);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function brokenVariants(): array
    {
        return [
            'a duplicate ID' => ['broken-duplicate-id.md', 'ids', 42],
            'a missing ID' => ['broken-missing-id.md', 'ids', 42],
            'a skills row naming a role that does not exist' => ['broken-skill-names-no-role.md', 'skills_index_references', 32],
        ];
    }

    /**
     * A missing section has no line to point at: the problem is the absence.
     */
    public function test_a_missing_section_is_reported_without_a_line(): void
    {
        $report = $this->lint('master-cv/broken-missing-section.md');

        $this->assertSame(['sections' => 1], $report->problemsByCheck());
        $this->assertNull($report->problems()[0]->line);
        $this->assertStringContainsString('Profile facts', $report->problems()[0]->message);
    }

    public function test_an_unknown_block_label_is_a_note_and_never_a_failure(): void
    {
        $report = $this->lint();

        $labels = array_values(array_filter(
            $report->notes(),
            static fn (Finding $finding): bool => $finding->check === 'block_labels',
        ));

        $this->assertCount(3, $labels);
        $this->assertSame(204, $labels[0]->line);
        $this->assertTrue($report->passes());
    }

    public function test_a_hierarchy_a_sub_bullet_does_not_belong_to_is_a_problem(): void
    {
        $cv = $this->parser()->parse(str_replace(
            '{#harlequin-transit.4.1}',
            '{#harlequin-transit.9.1}',
            $this->fixture(),
        ));

        $report = $this->linter()->lint($cv);

        $this->assertSame(['ids' => 1], $report->problemsByCheck());
        $this->assertStringContainsString('harlequin-transit.4', $report->problems()[0]->message);
    }

    public function test_an_id_that_is_not_well_formed_is_a_problem(): void
    {
        $cv = $this->parser()->parse(str_replace(
            '{#larkspur-media}',
            '{#Larkspur Media}',
            $this->fixture(),
        ));

        $report = $this->linter()->lint($cv);

        $this->assertArrayHasKey('ids', $report->problemsByCheck());
    }

    /**
     * No check may quote the CV into a log, so the log carries counts. The
     * report is what has to be able to produce them.
     */
    public function test_it_counts_problems_by_check(): void
    {
        $cv = $this->parser()->parse(str_replace(
            ['{#northgate-tickets.1}', '{#northgate-tickets.2}'],
            '',
            $this->fixture(),
        ));

        $this->assertSame(['ids' => 2], $this->linter()->lint($cv)->problemsByCheck());
    }

    /**
     * A finding says what is wrong; what to do about it differs between a
     * terminal and a screen with a button, so the report names the remedy and
     * leaves the wording to whoever is telling the author.
     */
    public function test_a_missing_id_names_the_remedy_and_does_not_spell_it_out(): void
    {
        $problem = $this->lint('master-cv/broken-missing-id.md')->problems()[0];

        $this->assertSame('This achievement has no ID.', $problem->message);
        $this->assertSame(Remedy::AddMissingIds, $problem->remedy);
    }

    public function test_a_remedy_is_named_once_however_many_findings_call_for_it(): void
    {
        $report = $this->linter()->lint($this->parser()->parse(str_replace(
            [' {#wrenbury-freight.1}', ' {#wrenbury-freight.2}'],
            '',
            $this->fixture('master-cv/minimal.md'),
        )));

        $this->assertCount(2, $report->problems());
        $this->assertSame([Remedy::AddMissingIds], $report->remedies());
    }

    public function test_a_problem_nothing_can_fix_for_the_author_names_no_remedy(): void
    {
        $report = $this->lint('master-cv/broken-duplicate-id.md');

        $this->assertNull($report->problems()[0]->remedy);
        $this->assertSame([], $report->remedies());
    }

    /**
     * A technology with no row gets one, so there is nothing left for a
     * coverage check to report.
     */
    public function test_a_tech_stack_entry_with_no_row_is_not_a_problem(): void
    {
        $report = $this->lint('master-cv/derives-a-skill.md');

        $this->assertTrue($report->passes(), $this->describe($report));
        $this->assertSame([], $report->notes());
    }

    /**
     * Two roles spelling one technology differently are two skills, which the
     * author may have meant — so it is said and never enforced.
     */
    public function test_two_names_that_differ_only_trivially_are_a_note_and_never_a_failure(): void
    {
        $report = $this->linter()->lint($this->parser()->parse(str_replace(
            "- Data: Redis\n",
            "- Data: Redis\n- Caching: Redis 7\n",
            $this->fixture('master-cv/derives-a-skill.md'),
        )));

        $notes = $this->notesFrom($report, 'skill_names');

        $this->assertTrue($report->passes());
        $this->assertCount(1, $notes);
        $this->assertSame(
            '"Redis 7" and "Redis" on line 46 differ only in how they are written.',
            $notes[0]->message,
        );
    }

    /**
     * Two rows that each pin a version are two deliberate statements, not one
     * skill written twice.
     */
    public function test_names_pinning_different_versions_are_left_alone(): void
    {
        $this->assertSame([], $this->notesFrom($this->lint(), 'skill_names'));
    }

    /**
     * @return list<Finding>
     */
    private function notesFrom(LintReport $report, string $check): array
    {
        return array_values(array_filter(
            $report->notes(),
            static fn (Finding $finding): bool => $finding->check === $check,
        ));
    }

    private function lint(string $fixture = 'master-cv.md'): LintReport
    {
        return $this->linter()->lint($this->parseFixture($fixture));
    }

    private function linter(): MasterCvLinter
    {
        return new MasterCvLinter(
            new RecognisedSectionsPresent,
            new IdsArePresentAndUnique,
            new SkillsIndexNamesRealRoles,
            new SkillNamesAreDistinct,
            new BlockLabelsAreRecognised,
        );
    }

    private function describe(LintReport $report): string
    {
        return implode("\n", array_map(
            static fn (Finding $finding): string => $finding->line.' '.$finding->message,
            $report->problems(),
        ));
    }
}
