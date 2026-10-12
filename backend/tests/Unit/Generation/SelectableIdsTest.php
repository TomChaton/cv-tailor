<?php

namespace Tests\Unit\Generation;

use App\Generation\SelectableIds;
use App\MasterCv\ParsedMasterCv;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class SelectableIdsTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_offers_every_role_achievement_and_skill_id_in_the_cv(): void
    {
        $cv = $this->parseFixture();

        $this->assertSame($this->everyId($cv), $this->ids($cv));
    }

    public function test_it_offers_each_id_once(): void
    {
        $ids = $this->ids($this->parseFixture());

        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * Not selectable means not offered. The author has said the fact is
     * unknown, so there is nothing to choose.
     */
    public function test_it_withholds_content_marked_todo(): void
    {
        $ids = $this->ids($this->parseFixture());

        $this->assertContains('larkspur-media', $ids);
        $this->assertContains('larkspur-media.1', $ids);
        $this->assertNotContains('larkspur-media.2', $ids);
    }

    /**
     * A role the policy omits by default is still on offer: the model may
     * promote it, and it cannot promote what it was never shown.
     */
    public function test_it_offers_a_role_the_policy_omits_by_default(): void
    {
        $this->assertContains('larkspur-media', $this->ids($this->parseFixture()));
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
     * @return list<string>
     */
    private function ids(ParsedMasterCv $cv): array
    {
        return SelectableIds::from($cv)->ids;
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
