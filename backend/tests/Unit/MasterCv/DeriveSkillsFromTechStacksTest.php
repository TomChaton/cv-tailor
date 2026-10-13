<?php

namespace Tests\Unit\MasterCv;

use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Skill;
use App\MasterCv\SkillGroup;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class DeriveSkillsFromTechStacksTest extends TestCase
{
    use MasterCvFixtures;

    public function test_a_technology_with_no_row_is_derived_against_the_role_whose_stack_names_it(): void
    {
        $cv = $this->parseFixture('master-cv/derives-a-skill.md');

        $redis = $this->skill($cv, 'redis');

        $this->assertNotNull($redis);
        $this->assertTrue($redis->derived);
        $this->assertSame('Redis', $redis->name);
        $this->assertSame('Wrenbury Freight', $redis->whereUsed);
        $this->assertSame(46, $redis->line);
        $this->assertSame(['skills_written' => 1, 'skills_derived' => 1], $this->skillCounts($cv));
    }

    public function test_a_technology_in_several_stacks_is_one_skill_naming_all_of_them(): void
    {
        $cv = $this->parser()->parse($this->withSecondRole());

        $redis = $this->skill($cv, 'redis');

        $this->assertNotNull($redis);
        $this->assertSame('Wrenbury Freight, Pentland Haulage', $redis->whereUsed);
    }

    /**
     * The index stays the place to say what the derivation cannot, so where a
     * row exists it stands and nothing is derived beside it — which is what
     * makes this change cost an existing CV nothing.
     */
    public function test_a_hand_written_row_wins_and_nothing_is_derived_for_it(): void
    {
        $cv = $this->parser()->parse(str_replace(
            "| PHP 8.x (8.3) | Wrenbury Freight |\n",
            "| PHP 8.x (8.3) | Wrenbury Freight |\n| Redis (sessions) | Wrenbury Freight |\n",
            $this->fixture('master-cv/derives-a-skill.md'),
        ));

        $this->assertSame(['skills_written' => 2, 'skills_derived' => 0], $this->skillCounts($cv));
        $this->assertNull($this->skill($cv, 'redis'));

        $written = $this->skill($cv, 'redis-sessions');

        $this->assertNotNull($written);
        $this->assertFalse($written->derived);
        $this->assertSame('Redis (sessions)', $written->name);
    }

    public function test_a_derived_skills_id_is_what_a_written_row_for_the_same_name_would_get(): void
    {
        $derived = $this->skill($this->parseFixture('master-cv/derives-a-skill.md'), 'redis');

        $written = $this->skill($this->parser()->parse(str_replace(
            "| PHP 8.x (8.3) | Wrenbury Freight |\n",
            "| PHP 8.x (8.3) | Wrenbury Freight |\n| Redis | Wrenbury Freight |\n",
            $this->fixture('master-cv/derives-a-skill.md'),
        )), 'redis');

        $this->assertNotNull($derived);
        $this->assertNotNull($written);
        $this->assertSame(Skill::idFor('Redis'), $derived->id);
        $this->assertSame($written->id, $derived->id);
        $this->assertTrue($derived->derived);
        $this->assertFalse($written->derived);
    }

    public function test_a_derived_skill_is_grouped_under_the_label_on_its_stack_line(): void
    {
        $cv = $this->parseFixture('master-cv/derives-a-skill.md');

        $group = $this->group($cv, 'Data');

        $this->assertNotNull($group);
        $this->assertSame(['Redis'], array_map(static fn (Skill $s): string => $s->name, $group->skills));
    }

    /**
     * `Data` and `data / search` obviously mean one shelf, and the first
     * spelling names it.
     */
    public function test_two_spellings_of_a_label_do_not_produce_two_groups(): void
    {
        $cv = $this->parser()->parse($this->withSecondRole());

        $this->assertSame(
            ['Languages', 'Data', 'Other'],
            array_map(static fn (SkillGroup $g): string => $g->heading, $cv->skillGroups),
        );

        $group = $this->group($cv, 'Data');

        $this->assertNotNull($group);
        $this->assertSame(
            ['Redis', 'Memcached'],
            array_map(static fn (Skill $s): string => $s->name, $group->skills),
        );
    }

    public function test_an_entry_on_an_unlabelled_line_is_grouped_under_a_catch_all(): void
    {
        $cv = $this->parser()->parse($this->withSecondRole());

        $group = $this->group($cv, 'Other');

        $this->assertNotNull($group);
        $this->assertSame(['Beanstalkd'], array_map(static fn (Skill $s): string => $s->name, $group->skills));
    }

    /**
     * Deleting a row the stacks already state leaves the CV saying the same
     * thing, which is the whole of why the index is derived rather than
     * checked.
     */
    public function test_the_fictional_cvs_row_comes_back_derived_when_it_is_deleted(): void
    {
        $this->assertSame(
            ['skills_written' => 44, 'skills_derived' => 0],
            $this->skillCounts($this->parseFixture()),
        );

        $cv = $this->parser()->parse(str_replace(
            "| Redis | Harlequin Transit |\n",
            '',
            $this->fixture(),
        ));

        $redis = $this->skill($cv, 'redis');

        $this->assertNotNull($redis);
        $this->assertTrue($redis->derived);
        $this->assertSame('Harlequin Transit', $redis->whereUsed);
        $this->assertSame(['skills_written' => 43, 'skills_derived' => 1], $this->skillCounts($cv));
    }

    /**
     * A derived row marks an independent project as one, the way the author's
     * own rows do. A row reading only "Quayside Charts" would offer the model
     * a technology with nothing to say it was not commercial work.
     */
    public function test_a_derived_row_says_when_its_evidence_is_an_independent_project(): void
    {
        $cv = $this->parser()->parse(str_replace(
            "| D3 | Quayside Charts |\n",
            '',
            $this->fixture(),
        ));

        $d3 = $this->skill($cv, 'd3');

        $this->assertNotNull($d3);
        $this->assertSame('Independent: Quayside Charts', $d3->whereUsed);
    }

    private function withSecondRole(): string
    {
        $role = <<<'MD'
            ### Pentland Haulage — Backend Engineer {#pentland-haulage}

            - **Dates:** January 2015 – February 2019

            **Achievements & projects**
            - Built the depot rota planner. {#pentland-haulage.1}

            **Tech stack**
            - data / search: Redis, Memcached
            - Beanstalkd

            ## Do not claim
            MD;

        return str_replace('## Do not claim', $role, $this->fixture('master-cv/derives-a-skill.md'));
    }

    /**
     * @return array<string, int>
     */
    private function skillCounts(ParsedMasterCv $cv): array
    {
        $counts = $cv->counts();

        return ['skills_written' => $counts['skills_written'], 'skills_derived' => $counts['skills_derived']];
    }

    private function skill(ParsedMasterCv $cv, string $id): ?Skill
    {
        foreach ($cv->allSkills() as $skill) {
            if ($skill->id === $id) {
                return $skill;
            }
        }

        return null;
    }

    private function group(ParsedMasterCv $cv, string $heading): ?SkillGroup
    {
        foreach ($cv->skillGroups as $group) {
            if ($group->heading === $heading) {
                return $group;
            }
        }

        return null;
    }
}
