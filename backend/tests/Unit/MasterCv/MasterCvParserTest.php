<?php

namespace Tests\Unit\MasterCv;

use App\MasterCv\ContentMarker;
use App\MasterCv\Role;
use App\MasterCv\RoleKind;
use App\MasterCv\RoleMode;
use App\MasterCv\Section;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class MasterCvParserTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_finds_every_recognised_section_despite_their_qualifiers(): void
    {
        $cv = $this->parseFixture();

        foreach (Section::cases() as $section) {
            $this->assertTrue($cv->has($section), $section->value.' was not found');
        }
    }

    public function test_a_heading_it_does_not_recognise_is_not_filed_under_the_one_above_it(): void
    {
        $cv = $this->parser()->parse(<<<'MARKDOWN'
            ## Profile facts

            - A fact about the person.

            ## Referees

            - Someone who would vouch for them.
            MARKDOWN);

        $this->assertSame(['A fact about the person.'], $cv->profileFacts);
    }

    public function test_it_reads_the_presentation_policy_from_the_front_matter(): void
    {
        $policy = $this->parseFixture()->presentation;

        $this->assertSame(2, $policy->targetPages);
        $this->assertSame(2018, $policy->condenseRolesEndingBefore);
        $this->assertSame(2012, $policy->omitRolesEndingBefore);
        $this->assertSame(['Senior Backend Engineer', 'Platform Engineer'], $policy->headlineTitles);
    }

    public function test_a_front_matter_without_a_policy_gets_working_defaults(): void
    {
        $cv = $this->parseFixture('master-cv/minimal.md');

        $this->assertSame(2, $cv->presentation->targetPages);
        $this->assertNull($cv->presentation->condenseRolesEndingBefore);
        $this->assertNull($cv->presentation->omitRolesEndingBefore);
        $this->assertNull($cv->presentation->headlineTitles);

        foreach ($cv->roles as $role) {
            $this->assertSame(RoleMode::Full, $role->defaultMode);
        }
    }

    public function test_keys_the_parser_does_not_care_about_are_carried_rather_than_refused(): void
    {
        $this->assertSame('master-cv', $this->parseFixture()->frontMatter['document'] ?? null);
    }

    public function test_the_contact_section_is_read_as_identity(): void
    {
        $contact = $this->parseFixture()->contact;

        $this->assertArrayHasKey('Phone', $contact->fields);
        $this->assertArrayHasKey('Email', $contact->fields);
        $this->assertArrayHasKey(
            'Full postal address (use only if a form requires it)',
            $contact->fields,
        );
    }

    public function test_it_reads_the_facts_that_are_plain_bullet_lists(): void
    {
        $cv = $this->parseFixture();

        $this->assertSame(
            ['Senior Backend Engineer', 'Platform Engineer', 'Search Specialist'],
            $cv->headlineTitles,
        );
        $this->assertCount(4, $cv->profileFacts);
        $this->assertCount(1, $cv->awards);
        $this->assertCount(4, $cv->interests);
    }

    public function test_the_rules_are_kept_verbatim_as_well_as_itemised(): void
    {
        $cv = $this->parseFixture();

        $this->assertStringContainsString('This file is the only source of facts.', $cv->agentRules->raw);
        $this->assertCount(8, $cv->agentRules->items);
        $this->assertCount(8, $cv->doNotClaim->items);
        $this->assertStringContainsString('Kafka', $cv->doNotClaim->raw);
    }

    public function test_the_skills_index_is_read_in_both_of_its_row_forms(): void
    {
        $groups = $this->parseFixture()->skillGroups;

        $this->assertCount(7, $groups);

        $languages = $groups[0];
        $this->assertSame('Languages', $languages->heading);
        $this->assertCount(7, $languages->skills);
        $this->assertSame('PHP 8.x (8.3)', $languages->skills[0]->name);
        $this->assertSame('Harlequin Transit (2021–present)', $languages->skills[0]->whereUsed);

        $waysOfWorking = $groups[5];
        $this->assertSame('Ways of working & leadership', $waysOfWorking->heading);
        $this->assertSame('Code review as a team practice', $waysOfWorking->skills[0]->name);
        $this->assertSame('Harlequin Transit', $waysOfWorking->skills[0]->whereUsed);
        $this->assertNull($waysOfWorking->skills[4]->whereUsed);
    }

    public function test_a_skill_is_identified_by_a_slug_of_its_name(): void
    {
        $skills = $this->parseFixture()->skillGroups[0]->skills;

        $this->assertSame('php-8x-83', $skills[0]->id);
        $this->assertSame('typescript', $skills[4]->id);
    }

    public function test_it_reads_a_role_as_metadata_blocks_and_bullets(): void
    {
        $role = $this->role('harlequin-transit');

        $this->assertSame(RoleKind::Employment, $role->kind);
        $this->assertSame('Harlequin Transit', $role->employer);
        $this->assertSame('Senior Backend Engineer', $role->title);
        $this->assertSame('September 2021 – present', $role->metadata['Dates'] ?? null);

        $dates = $role->dates;
        $this->assertNotNull($dates);
        $this->assertSame(2021, $dates->startYear);
        $this->assertNull($dates->endYear);
        $this->assertTrue($dates->current);
        $this->assertStringStartsWith('Joined to modernise', (string) $role->context);
    }

    public function test_a_metadata_key_it_has_never_seen_is_kept_rather_than_refused(): void
    {
        $this->assertSame('180 across the business', $this->role('harlequin-transit')->metadata['Headcount'] ?? null);
    }

    public function test_a_tech_stack_is_inventory_and_not_achievements(): void
    {
        $role = $this->role('harlequin-transit');

        $aws = array_values(array_filter(
            $role->techStack,
            static fn ($entry): bool => $entry->category === 'AWS',
        ));

        $this->assertCount(1, $aws);
        $this->assertSame(
            ['ECS', 'SQS (incl. dead-letter replay)', 'S3', 'CloudWatch'],
            $aws[0]->terms,
        );

        foreach ($role->allAchievements() as $achievement) {
            $this->assertNotSame('Tech stack', $achievement->blockLabel);
        }
    }

    public function test_the_label_above_a_bullet_decides_what_the_bullet_is(): void
    {
        $role = $this->role('harlequin-transit');

        $this->assertCount(9, $role->achievements);
        $this->assertSame('Responsibilities', $role->achievements[0]->blockLabel);
        $this->assertSame('Achievements & projects', $role->achievements[3]->blockLabel);
    }

    public function test_an_unknown_block_label_holds_achievements_and_is_reported(): void
    {
        $role = $this->role('northgate-tickets');

        $this->assertSame(
            [['label' => 'Platform work', 'line' => 198]],
            $role->unknownBlocks,
        );
        $this->assertSame('Platform work', $role->achievements[5]->blockLabel);
        $this->assertSame('northgate-tickets.6', $role->achievements[5]->id);
    }

    public function test_sub_bullets_are_selectable_in_their_own_right(): void
    {
        $achievement = $this->role('harlequin-transit')->achievements[3];

        $this->assertSame('harlequin-transit.4', $achievement->id);
        $this->assertCount(3, $achievement->children);
        $this->assertSame('harlequin-transit.4.1', $achievement->children[0]->id);
        $this->assertTrue($achievement->children[0]->selectable);
    }

    public function test_a_role_with_no_context_block_has_no_context(): void
    {
        $this->assertNull($this->role('northgate-tickets')->context);
    }

    public function test_todo_content_is_never_selectable(): void
    {
        $bullets = $this->role('larkspur-media')->achievements;

        $this->assertTrue($bullets[0]->selectable);
        $this->assertFalse($bullets[1]->selectable);
        $this->assertContains(ContentMarker::Todo, $bullets[1]->markers);
    }

    public function test_content_marked_do_not_elaborate_may_be_used_verbatim_only(): void
    {
        $bullet = $this->role('northgate-tickets')->achievements[4];

        $this->assertTrue($bullet->verbatimOnly);
        $this->assertTrue($bullet->selectable);
        $this->assertContains(ContentMarker::NotRecorded, $bullet->markers);
        $this->assertContains(ContentMarker::DoNotElaborate, $bullet->markers);
    }

    public function test_role_modes_default_from_the_two_cutoff_years(): void
    {
        $this->assertSame(RoleMode::Full, $this->role('harlequin-transit')->defaultMode);
        $this->assertSame(RoleMode::Full, $this->role('northgate-tickets')->defaultMode);
        $this->assertSame(RoleMode::Condensed, $this->role('pelham-civic-data')->defaultMode);
        $this->assertSame(RoleMode::Omitted, $this->role('larkspur-media')->defaultMode);
    }

    public function test_independent_projects_are_kept_apart_from_employment(): void
    {
        $cv = $this->parseFixture();

        $this->assertCount(4, $cv->roles);
        $this->assertCount(2, $cv->independentProjects);

        foreach ($cv->independentProjects as $project) {
            $this->assertSame(RoleKind::Independent, $project->kind);
        }
    }

    public function test_a_labelled_value_on_a_line_of_its_own_is_metadata(): void
    {
        $project = $this->role('fathom-index');

        $this->assertStringStartsWith('product owner', $project->metadata["Jordan's role"] ?? '');
    }

    public function test_a_tech_stack_written_as_a_metadata_bullet_is_still_a_tech_stack(): void
    {
        $project = $this->role('quayside-charts');

        $this->assertArrayNotHasKey('Tech stack', $project->metadata);
        $this->assertSame(['Vue 3', 'D3', 'SQLite'], $project->technologies());
    }

    public function test_it_counts_what_it_parsed(): void
    {
        $this->assertSame([
            'roles' => 4,
            'projects' => 2,
            'achievements' => 25,
            'sub_bullets' => 7,
            'skills' => 44,
        ], $this->parseFixture()->counts());
    }

    public function test_the_same_cv_hashes_the_same_and_a_changed_fact_does_not(): void
    {
        $content = $this->fixture();

        $this->assertSame(
            $this->parser()->parse($content)->hash(),
            $this->parser()->parse($content)->hash(),
        );

        $this->assertNotSame(
            $this->parser()->parse($content)->hash(),
            $this->parser()->parse(str_replace('Terraform', 'Pulumi', $content))->hash(),
        );
    }

    /**
     * The worst defect this could ship is identity reaching the model, so the
     * check is made on the whole structure rather than on the field that is
     * supposed to hold it.
     */
    public function test_identity_is_absent_from_the_material_bound_for_the_model(): void
    {
        $cv = $this->parseFixture();
        $payload = json_encode($cv->withoutIdentity(), JSON_THROW_ON_ERROR);

        $this->assertNotSame([], $cv->contact->values());

        foreach ($cv->contact->values() as $value) {
            $this->assertStringNotContainsString($value, $payload);
        }

        $this->assertStringContainsString(
            '07700 900123',
            json_encode($cv->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    private function role(string $id): Role
    {
        foreach ($this->parseFixture()->allRoles() as $role) {
            if ($role->id === $id) {
                return $role;
            }
        }

        $this->fail('The fixture has no role with the ID '.$id);
    }
}
