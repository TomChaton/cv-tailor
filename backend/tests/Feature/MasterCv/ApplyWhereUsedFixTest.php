<?php

namespace Tests\Feature\MasterCv;

use App\MasterCv\Fixes\ApplyWhereUsedFix;
use App\MasterCv\Lint\FindingLevel;
use App\MasterCv\Skill;
use App\Observability\EventLogger;
use Tests\Concerns\MasterCvFixtures;
use Tests\Support\RecordingEventLogger;
use Tests\TestCase;

/**
 * Answering a "Where used" finding, whichever level it came at.
 *
 * The question is the same one for a note as for a problem — which of this
 * document's roles and projects does the row name — so the answer is written
 * the same way, and the log says which kind of finding was being answered.
 */
class ApplyWhereUsedFixTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_writes_the_chosen_roles_into_that_rows_cell(): void
    {
        $markdown = $this->fixture('master-cv/broken-skill-names-no-role.md');

        $this->assertSame(
            $this->fixture('master-cv/minimal.md'),
            $this->answer($markdown, 'php-8x-83', ['wrenbury-freight'], FindingLevel::Problem),
        );
    }

    /**
     * A note's row names things that do exist beside the one that does not,
     * and those are what the modal opens with. Saved back untouched it is not
     * a correction, so nothing is written: a rewrite would reword the parts
     * that were right, which is harm the finding never asked for.
     */
    public function test_a_selection_the_row_already_names_writes_nothing(): void
    {
        $markdown = $this->withNotedPhpRow();

        $this->assertSame($markdown, $this->answer(
            $markdown,
            'php-7x-70-74',
            ['harlequin-transit', 'northgate-tickets', 'pelham-civic-data'],
            FindingLevel::Note,
        ));
    }

    public function test_correcting_a_notes_row_keeps_the_roles_it_named_and_drops_the_rest(): void
    {
        $markdown = $this->withNotedPhpRow();

        $result = $this->answer(
            $markdown,
            'php-7x-70-74',
            ['harlequin-transit', 'northgate-tickets', 'pelham-civic-data', 'larkspur-media'],
            FindingLevel::Note,
        );

        $this->assertStringContainsString(
            '| PHP 7.x (7.0, 7.4) | Harlequin Transit, Northgate Tickets, Pelham Civic Data, Larkspur Media |',
            $result,
        );
        $this->assertStringNotContainsString('Pentland Haulage', $result);
    }

    public function test_a_fix_applied_to_a_note_says_so_in_the_log(): void
    {
        $log = $this->recording();

        $this->answer($this->withNotedPhpRow(), 'php-7x-70-74', ['harlequin-transit'], FindingLevel::Note);

        $line = $log->first('master_cv.where_used_fixed');

        $this->assertNotNull($line);
        $this->assertSame('note', $line['context']['finding_level']);
        $this->assertSame(1, $line['context']['chosen']);
        $this->assertTrue($line['context']['rewritten']);
    }

    public function test_a_fix_applied_to_a_problem_is_the_same_line_at_the_other_level(): void
    {
        $log = $this->recording();

        $this->answer(
            $this->fixture('master-cv/broken-skill-names-no-role.md'),
            'php-8x-83',
            ['wrenbury-freight'],
            FindingLevel::Problem,
        );

        $line = $log->first('master_cv.where_used_fixed');

        $this->assertNotNull($line);
        $this->assertSame('problem', $line['context']['finding_level']);
        $this->assertTrue($line['context']['rewritten']);
    }

    public function test_a_selection_that_wrote_nothing_says_so_in_the_same_line(): void
    {
        $log = $this->recording();

        $this->answer(
            $this->withNotedPhpRow(),
            'php-7x-70-74',
            ['harlequin-transit', 'northgate-tickets', 'pelham-civic-data'],
            FindingLevel::Note,
        );

        $line = $log->first('master_cv.where_used_fixed');

        $this->assertNotNull($line);
        $this->assertFalse($line['context']['rewritten']);
    }

    /**
     * The master CV is the most personal thing this application holds, so a
     * line about a fix carries counts and outcomes and nothing the document
     * says.
     */
    public function test_it_logs_nothing_the_document_says(): void
    {
        $log = $this->recording();

        $this->answer($this->withNotedPhpRow(), 'php-7x-70-74', ['harlequin-transit'], FindingLevel::Note);

        $written = (string) json_encode($log->lines);

        $this->assertStringNotContainsString('Harlequin Transit', $written);
        $this->assertStringNotContainsString('PHP 7.x', $written);
    }

    /**
     * @param  list<string>  $chosen
     */
    private function answer(string $markdown, string $id, array $chosen, FindingLevel $level): string
    {
        $editing = $this->parser()->parse($markdown);

        return app(ApplyWhereUsedFix::class)
            ->handle($markdown, $editing, $this->row($editing->writtenSkill($id), $id), $chosen, $level);
    }

    private function row(?Skill $skill, string $id): Skill
    {
        $this->assertNotNull($skill, 'The CV has no row "'.$id.'".');

        return $skill;
    }

    private function recording(): RecordingEventLogger
    {
        $log = new RecordingEventLogger;
        $this->instance(EventLogger::class, $log);

        return $log;
    }

    /**
     * The fictional CV with one skills-index row naming an employer it does
     * not have beside three roles it does — the shape that earns a note
     * rather than a problem. The qualifier on the first of them is the
     * author's own prose, and is what an untouched save must not lose.
     */
    private function withNotedPhpRow(): string
    {
        return str_replace(
            '| PHP 7.x (7.0, 7.4) | Harlequin Transit (legacy, upgraded), Northgate Tickets, Pelham Civic Data |',
            '| PHP 7.x (7.0, 7.4) | Harlequin Transit (legacy, upgraded), Pentland Haulage, Northgate Tickets, Pelham Civic Data |',
            $this->fixture(),
        );
    }
}
