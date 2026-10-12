<?php

namespace Tests\Unit\Generation;

use App\Generation\SelectableIds;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\Skill;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class SelectableIdsTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_offers_every_role_achievement_and_skill_id_in_the_cv(): void
    {
        $cv = $this->parseFixture();

        $this->assertSame($this->everyId($cv), SelectableIds::from($cv)->all);
    }

    public function test_it_offers_each_id_once(): void
    {
        $ids = SelectableIds::from($this->parseFixture())->all;

        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * Not selectable means not offered. The author has said the fact is
     * unknown, so there is nothing to choose.
     */
    public function test_it_withholds_content_marked_todo(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertContains('larkspur-media', $ids->employmentRoles);
        $this->assertContains('larkspur-media.1', $ids->items);
        $this->assertNotContains('larkspur-media.2', $ids->items);
        $this->assertNotContains('larkspur-media.2', $ids->all);
    }

    /**
     * A role the policy omits by default is still on offer: the model may
     * promote it, and it cannot promote what it was never shown.
     */
    public function test_it_offers_a_role_the_policy_omits_by_default(): void
    {
        $this->assertContains('larkspur-media', SelectableIds::from($this->parseFixture())->employmentRoles);
    }

    /**
     * Employment and independent projects are kept apart because the fields
     * that take them are: a project returned under an employer claims
     * commercial experience nobody paid for.
     */
    public function test_employment_and_independent_projects_are_separate_lists(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertSame([
            'harlequin-transit',
            'northgate-tickets',
            'pelham-civic-data',
            'larkspur-media',
        ], $ids->employmentRoles);

        $this->assertSame(['fathom-index', 'quayside-charts'], $ids->independentProjects);
    }

    public function test_the_skill_list_holds_only_rows_of_the_skills_index(): void
    {
        $cv = $this->parseFixture();
        $skills = SelectableIds::from($cv)->skills;

        $this->assertSame(
            array_values(array_map(
                static fn (Skill $skill): string => $skill->id,
                array_filter($cv->allSkills(), static fn (Skill $skill): bool => $skill->selectable),
            )),
            $skills,
        );

        $this->assertNotContains('harlequin-transit', $skills);
        $this->assertNotContains('harlequin-transit.4', $skills);
    }

    public function test_the_item_list_holds_only_bullets(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertContains('harlequin-transit.4', $ids->items);
        $this->assertContains('harlequin-transit.4.1', $ids->items);
        $this->assertContains('fathom-index.1.2', $ids->items);
        $this->assertNotContains('harlequin-transit', $ids->items);
        $this->assertNotContains('fathom-index', $ids->items);
        $this->assertNotContains('php-8-x-8-3', $ids->items);
    }

    /**
     * A mode is promoted on a role or a project, never on a bullet or a skill.
     */
    public function test_only_roles_and_projects_can_be_promoted(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertSame(
            [...$ids->employmentRoles, ...$ids->independentProjects],
            $ids->promotable(),
        );
    }

    /**
     * Every kind-scoped list is drawn from the broad one, so a field scoped by
     * kind can never offer an ID the broad list does not hold.
     */
    public function test_every_kind_is_part_of_the_broad_list(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $kinds = [...$ids->employmentRoles, ...$ids->independentProjects, ...$ids->items, ...$ids->skills];

        $this->assertSame([], array_diff($kinds, $ids->all));
        $this->assertSame([], array_diff($ids->all, $kinds));
    }

    public function test_the_front_matter_narrows_the_headline_titles_on_offer(): void
    {
        $titles = SelectableIds::from($this->parseFixture())->headlineTitles;

        $this->assertSame(['Senior Backend Engineer', 'Platform Engineer'], $titles);
    }

    public function test_every_title_is_on_offer_when_the_front_matter_names_none(): void
    {
        $cv = $this->parseFixture();
        $without = $this->replaceFrontMatterTitles([]);

        $this->assertSame($cv->headlineTitles, SelectableIds::from($without)->headlineTitles);
    }

    /**
     * The front matter narrows the headline titles section; it cannot add to
     * it. A list that narrows the section to nothing has said nothing.
     */
    public function test_the_front_matter_cannot_add_a_title_the_cv_does_not_list(): void
    {
        $invented = $this->replaceFrontMatterTitles(['Chief Executive']);

        $this->assertSame(
            $this->parseFixture()->headlineTitles,
            SelectableIds::from($invented)->headlineTitles,
        );
    }

    /**
     * The same walk, written out, so the expectation is the fixture rather
     * than the code under test.
     *
     * @return list<string>
     */
    private function everyId(ParsedMasterCv $cv): array
    {
        $ids = [];

        foreach ($cv->allRoles() as $role) {
            $ids[] = $role->id;

            foreach ($role->allAchievements() as $achievement) {
                if ($achievement->selectable) {
                    $ids[] = $achievement->id;
                }
            }
        }

        foreach ($cv->allSkills() as $skill) {
            if ($skill->selectable) {
                $ids[] = $skill->id;
            }
        }

        return array_values(array_filter($ids, static fn (?string $id): bool => $id !== null));
    }

    /**
     * @param  list<string>  $titles
     */
    private function replaceFrontMatterTitles(array $titles): ParsedMasterCv
    {
        $block = $titles === []
            ? ''
            : "  headline_titles:\n".implode('', array_map(
                static fn (string $title): string => '    - '.$title."\n",
                $titles,
            ));

        $content = preg_replace(
            '/  headline_titles:\n(    - .*\n)+/',
            $block,
            $this->fixture(),
        );

        $this->assertIsString($content);

        return $this->parser()->parse($content);
    }
}
