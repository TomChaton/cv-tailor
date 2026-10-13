<?php

namespace Tests\Unit\MasterCv;

use App\MasterCv\Lint\Checks\BlockLabelsAreRecognised;
use App\MasterCv\Lint\Checks\IdsArePresentAndUnique;
use App\MasterCv\Lint\Checks\RecognisedSectionsPresent;
use App\MasterCv\Lint\Checks\SkillsIndexNamesRealRoles;
use App\MasterCv\Lint\Checks\TechStackIsInSkillsIndex;
use App\MasterCv\Lint\Finding;
use App\MasterCv\Lint\LintReport;
use App\MasterCv\Lint\MasterCvLinter;
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
            'a tech stack entry missing from the index' => ['broken-tech-stack-not-in-index.md', 'tech_stack_coverage', 46],
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
            new TechStackIsInSkillsIndex,
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
