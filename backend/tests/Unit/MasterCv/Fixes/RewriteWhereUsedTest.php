<?php

namespace Tests\Unit\MasterCv\Fixes;

use App\MasterCv\Fixes\RewriteWhereUsed;
use App\MasterCv\Fixes\WhereUsedNotRewritten;
use App\MasterCv\Skill;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

/**
 * The one thing in the system that edits the master CV on its author's
 * behalf.
 *
 * Every test here is the same test from a different angle: one row changed,
 * and the rest of the document untouched to the character.
 */
class RewriteWhereUsedTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_rewrites_a_table_rows_second_column_and_nothing_else(): void
    {
        $source = $this->fixture('master-cv/broken-skill-names-no-role.md');
        $skill = $this->skill($source, 'php-8x-83');

        $result = (new RewriteWhereUsed)->handle($source, $skill, 'Wrenbury Freight');

        $this->assertSame($this->fixture('master-cv/minimal.md'), $result);
    }

    public function test_it_rewrites_a_list_rows_second_column(): void
    {
        $source = $this->listFormCv('Code review as a team practice — Pentland Haulage');
        $skill = $this->skill($source, 'code-review-as-a-team-practice');

        $this->assertSame(
            $this->listFormCv('Code review as a team practice — Wrenbury Freight'),
            (new RewriteWhereUsed)->handle($source, $skill, 'Wrenbury Freight'),
        );
    }

    /**
     * A row claiming no roles is a legitimate answer for a skill its author
     * has not placed yet.
     */
    public function test_an_empty_cell_empties_a_table_rows_second_column(): void
    {
        $source = $this->fixture('master-cv/broken-skill-names-no-role.md');
        $skill = $this->skill($source, 'php-8x-83');

        $result = (new RewriteWhereUsed)->handle($source, $skill, '');

        $this->assertStringContainsString("| PHP 8.x (8.3) | |\n", $result);
        $this->assertSame(
            str_replace('| PHP 8.x (8.3) | Pentland Haulage |', '| PHP 8.x (8.3) | |', $source),
            $result,
        );
    }

    /**
     * A list row that trails off into a dash with nothing after it is not
     * what the author would have written.
     */
    public function test_an_empty_cell_takes_a_list_rows_dash_with_it(): void
    {
        $source = $this->listFormCv('Code review as a team practice — Pentland Haulage');
        $skill = $this->skill($source, 'code-review-as-a-team-practice');

        $this->assertSame(
            $this->listFormCv('Code review as a team practice'),
            (new RewriteWhereUsed)->handle($source, $skill, ''),
        );
    }

    public function test_it_leaves_every_other_line_of_a_long_document_identical(): void
    {
        $source = str_replace(
            '| Rust | Independent: Fathom Index (indexer) |',
            '| Rust | Pentland Haulage |',
            $this->fixture(),
        );

        $result = (new RewriteWhereUsed)->handle(
            $source,
            $this->skill($source, 'rust'),
            'Independent: Fathom Index',
        );

        $this->assertSame(
            explode("\n", str_replace(
                '| Rust | Pentland Haulage |',
                '| Rust | Independent: Fathom Index |',
                $source,
            )),
            explode("\n", $result),
        );
    }

    /**
     * The row is found by line and confirmed by name. A line that no longer
     * carries the row it carried is a refusal, not a guess: the alternative
     * is writing one skill's evidence onto another.
     */
    public function test_it_refuses_a_line_that_no_longer_carries_that_row(): void
    {
        $source = $this->fixture('master-cv/broken-skill-names-no-role.md');
        $skill = $this->skill($source, 'php-8x-83');

        $moved = str_replace(
            '| PHP 8.x (8.3) | Pentland Haulage |',
            '| Fortran | Pentland Haulage |',
            $source,
        );

        $this->expectException(WhereUsedNotRewritten::class);

        (new RewriteWhereUsed)->handle($moved, $skill, 'Wrenbury Freight');
    }

    public function test_it_refuses_a_line_that_is_not_there_at_all(): void
    {
        $source = $this->fixture('master-cv/broken-skill-names-no-role.md');
        $skill = $this->skill($source, 'php-8x-83');

        $this->expectException(WhereUsedNotRewritten::class);

        (new RewriteWhereUsed)->handle("# Nothing much\n", $skill, 'Wrenbury Freight');
    }

    public function test_it_refuses_a_line_that_is_not_a_skills_row(): void
    {
        $source = $this->fixture('master-cv/broken-skill-names-no-role.md');
        $row = $this->skill($source, 'php-8x-83');

        // A tech-stack line is a bullet, and nothing a guided fix may write
        // to: its label is not a skill name.
        $stack = new Skill(
            id: $row->id,
            name: $row->name,
            whereUsed: null,
            line: $this->lineOf($source, '- Languages: PHP 8.x (8.3)'),
            selectable: true,
            verbatimOnly: false,
            markers: [],
        );

        $this->expectException(WhereUsedNotRewritten::class);

        (new RewriteWhereUsed)->handle($source, $stack, 'Wrenbury Freight');
    }

    /**
     * A document written with CRLF keeps its endings, so the only line that
     * changes is still the only line that changes.
     */
    public function test_it_keeps_the_line_endings_the_document_was_written_with(): void
    {
        $source = str_replace("\n", "\r\n", $this->fixture('master-cv/broken-skill-names-no-role.md'));
        $skill = $this->skill($source, 'php-8x-83');

        $result = (new RewriteWhereUsed)->handle($source, $skill, 'Wrenbury Freight');

        $this->assertSame(
            str_replace("\n", "\r\n", $this->fixture('master-cv/minimal.md')),
            $result,
        );
    }

    private function skill(string $markdown, string $id): Skill
    {
        $skill = $this->parser()->parse($markdown)->writtenSkill($id);

        $this->assertNotNull($skill, 'The CV has no row "'.$id.'".');

        return $skill;
    }

    /**
     * The minimal CV with a list-form skills index, which is the other row
     * form the format accepts.
     */
    private function listFormCv(string $row): string
    {
        return str_replace(
            "| Skill | Where used |\n|---|---|\n| PHP 8.x (8.3) | Wrenbury Freight |",
            '- '.$row,
            $this->fixture('master-cv/minimal.md'),
        );
    }

    private function lineOf(string $markdown, string $text): int
    {
        foreach (explode("\n", $markdown) as $offset => $line) {
            if (trim($line) === $text) {
                return $offset + 1;
            }
        }

        $this->fail('The CV has no line "'.$text.'".');
    }
}
