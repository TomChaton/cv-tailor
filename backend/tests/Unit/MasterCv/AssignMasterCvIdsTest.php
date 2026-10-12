<?php

namespace Tests\Unit\MasterCv;

use App\MasterCv\Ids\AssignMasterCvIds;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class AssignMasterCvIdsTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_writes_the_ids_the_fictional_cv_already_carries(): void
    {
        $assignment = $this->assign()->handle($this->withoutIds());

        $this->assertSame($this->fixture(), $assignment->content);
    }

    public function test_it_reports_what_it_added_per_kind(): void
    {
        $assignment = $this->assign()->handle($this->withoutIds());

        $this->assertSame([
            'roles' => 4,
            'projects' => 2,
            'achievements' => 25,
            'sub_bullets' => 7,
        ], $assignment->added);
        $this->assertSame(38, $assignment->total());
    }

    public function test_it_changes_nothing_in_a_cv_that_already_has_ids(): void
    {
        $assignment = $this->assign()->handle($this->fixture());

        $this->assertSame($this->fixture(), $assignment->content);
        $this->assertSame(0, $assignment->total());
    }

    public function test_it_is_deterministic(): void
    {
        $this->assertSame(
            $this->assign()->handle($this->withoutIds())->content,
            $this->assign()->handle($this->withoutIds())->content,
        );
    }

    /**
     * The author reviews a diff before adopting the result, so the diff has to
     * contain added IDs and nothing else.
     */
    public function test_it_preserves_everything_but_the_ids_it_adds(): void
    {
        $content = $this->assign()->handle($this->withoutIds())->content;

        $this->assertSame(
            $this->withoutIds(),
            (string) preg_replace('/ \{#[^}]+\}/', '', $content),
        );
    }

    public function test_a_bullet_added_beneath_ones_that_have_ids_counts_on_from_the_highest(): void
    {
        $content = str_replace(
            '- TODO: the traffic the advertising section carried. {#larkspur-media.2}',
            "- TODO: the traffic the advertising section carried. {#larkspur-media.2}\n- A bullet the author has just written.",
            $this->fixture(),
        );

        $assignment = $this->assign()->handle($content);

        $this->assertStringContainsString(
            '- A bullet the author has just written. {#larkspur-media.3}',
            $assignment->content,
        );
        $this->assertSame(['achievements' => 1], array_filter($assignment->added));
    }

    public function test_two_employers_with_the_same_name_do_not_share_an_id(): void
    {
        $content = str_replace(
            '### Larkspur Media — Junior Developer {#larkspur-media}',
            '### Larkspur Media — Junior Developer',
            str_replace(
                '### Northgate Tickets — Backend Developer {#northgate-tickets}',
                '### Larkspur Media — Backend Developer',
                $this->fixture(),
            ),
        );

        $assignment = $this->assign()->handle($content);

        $this->assertStringContainsString('### Larkspur Media — Backend Developer {#larkspur-media}', $assignment->content);
        $this->assertStringContainsString('### Larkspur Media — Junior Developer {#larkspur-media-2}', $assignment->content);
    }

    public function test_an_id_is_never_added_to_a_skills_row(): void
    {
        $content = $this->assign()->handle($this->withoutIds())->content;

        $this->assertStringContainsString('| PHP 8.x (8.3) | Harlequin Transit (2021–present) |', $content);
    }

    private function assign(): AssignMasterCvIds
    {
        return new AssignMasterCvIds($this->parser());
    }

    private function withoutIds(): string
    {
        return (string) preg_replace('/ \{#[^}]+\}/', '', $this->fixture());
    }
}
