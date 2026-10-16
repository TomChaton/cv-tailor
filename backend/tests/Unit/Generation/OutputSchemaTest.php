<?php

namespace Tests\Unit\Generation;

use App\Generation\OutputSchema;
use App\Generation\RequirementPriority;
use App\Generation\RequirementStatus;
use App\Generation\SelectableIds;
use App\MasterCv\RoleMode;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class OutputSchemaTest extends TestCase
{
    use MasterCvFixtures;

    /**
     * The provider compiles the schema into a grammar and refuses one that is
     * too complex, so every enum value is spent out of a budget. A second enum
     * listing every ID of every kind spent that budget twice over to say what
     * the scoped enums had already said.
     */
    public function test_no_enum_lists_every_id_of_every_kind(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertNotSame([], $ids->all);

        foreach ($this->enums($this->schema()) as $path => $values) {
            $this->assertNotSame($ids->all, $values, $path);
            $this->assertSame([], array_diff($values, $this->ofOneKind($ids)), $path);
        }
    }

    /**
     * The guarantee the broad enum is traded for: the selection enums, which
     * decide what the CV says, are still built from the parsed CV.
     */
    public function test_selection_is_still_constrained_to_the_parsed_master_cv(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertSame($ids->items, $this->enum('item_id'));
        $this->assertSame($ids->skills, $this->enum('skill_id'));
        $this->assertSame($ids->employmentRoles, $this->enum('employment_role_id'));
        $this->assertSame($ids->independentProjects, $this->enum('independent_project_id'));

        foreach (['item_id', 'skill_id', 'employment_role_id', 'independent_project_id'] as $def) {
            $this->assertNotSame([], $this->enum($def), $def);
        }
    }

    public function test_content_marked_todo_is_absent_from_the_id_enum(): void
    {
        $this->assertContains('larkspur-media.1', $this->enum('item_id'));
        $this->assertNotContains('larkspur-media.2', $this->enum('item_id'));
    }

    /**
     * A master CV of a real size offers a few hundred IDs. The fictional
     * fixture every other test here uses is small enough that they would all
     * pass with the duplicate enum still in place.
     */
    public function test_a_cv_of_a_realistic_size_still_lists_no_id_twice(): void
    {
        $ids = SelectableIds::from($this->parseFixture('master-cv/realistic-size.md'));

        $this->assertGreaterThan(300, count($ids->all));

        $schema = (new OutputSchema)->for($ids);

        foreach ($this->enums($schema) as $path => $values) {
            $this->assertNotSame($ids->all, $values, $path);
        }

        $this->assertSame($ids->items, $this->subArray($schema, '$defs', 'item_id', 'enum'));
        $this->assertSame($ids->skills, $this->subArray($schema, '$defs', 'skill_id', 'enum'));
    }

    /**
     * An enum scoped to the kind of thing its field takes makes a malformed
     * selection impossible rather than detectable.
     */
    public function test_each_id_enum_holds_only_its_own_kind(): void
    {
        $ids = SelectableIds::from($this->parseFixture());

        $this->assertSame($ids->employmentRoles, $this->enum('employment_role_id'));
        $this->assertSame($ids->independentProjects, $this->enum('independent_project_id'));
        $this->assertSame($ids->items, $this->enum('item_id'));
        $this->assertSame($ids->skills, $this->enum('skill_id'));
        $this->assertSame($ids->promotable(), $this->enum('role_or_project_id'));
    }

    public function test_the_fields_that_take_one_kind_point_at_that_kind(): void
    {
        $this->assertSame(
            ['$ref' => '#/$defs/skill_id'],
            $this->subArray($this->properties(), 'key_skills', 'items'),
        );
        $this->assertSame(
            ['$ref' => '#/$defs/employment_role_id'],
            $this->subArray($this->defs(), 'role_selection', 'properties', 'id'),
        );
        $this->assertSame(
            ['$ref' => '#/$defs/independent_project_id'],
            $this->subArray($this->defs(), 'project_selection', 'properties', 'id'),
        );
        $this->assertSame(
            ['$ref' => '#/$defs/item_id'],
            $this->subArray($this->defs(), 'selected_item', 'properties', 'id'),
        );
        $this->assertSame(
            ['$ref' => '#/$defs/role_or_project_id'],
            $this->subArray($this->defs(), 'promotion', 'properties', 'id'),
        );
    }

    /**
     * Evidence legitimately spans every kind, so no scoped enum fits it and a
     * combined one is the budget spent twice. A citation names why something
     * was chosen and cannot itself put a claim in the CV, and `IdsResolve` and
     * `RewordingsCarrySupport` catch an ID that does not resolve before
     * anything renders.
     */
    public function test_evidence_takes_a_plain_string(): void
    {
        $string = ['type' => 'string'];

        $this->assertSame($string, $this->subArray($this->properties(), 'summary', 'properties', 'supports', 'items'));
        $this->assertSame($string, $this->subArray($this->defs(), 'selected_item', 'properties', 'supports', 'items'));
        $this->assertSame($string, $this->subArray($this->defs(), 'requirement', 'properties', 'evidence', 'items'));
    }

    public function test_the_headline_enum_is_the_titles_on_offer(): void
    {
        $this->assertSame(
            ['Senior Backend Engineer', 'Platform Engineer'],
            $this->enum('headline_title'),
        );
    }

    public function test_the_role_mode_enum_is_every_mode(): void
    {
        $this->assertSame(['full', 'condensed', 'omitted'], $this->enum('role_mode'));
    }

    /**
     * A requirement's priority and status exist as PHP enums, and the schema
     * is built from them, so the review screen and the model's answer cannot
     * come to disagree about which values there are.
     */
    public function test_a_requirements_priority_and_status_come_from_their_enums(): void
    {
        $this->assertSame(
            ['type' => 'string', 'enum' => RequirementPriority::values()],
            $this->subArray($this->defs(), 'requirement', 'properties', 'priority'),
        );
        $this->assertSame(
            ['type' => 'string', 'enum' => RequirementStatus::values()],
            $this->subArray($this->defs(), 'requirement', 'properties', 'status'),
        );
        $this->assertSame(['must', 'nice'], RequirementPriority::values());
        $this->assertSame(['met', 'partial', 'gap'], RequirementStatus::values());
    }

    /**
     * An ID list runs to a few hundred entries, so it sits in `$defs` once and
     * every field that takes one points at it rather than carrying a copy.
     */
    public function test_an_id_list_is_defined_once_and_referenced_rather_than_inlined(): void
    {
        $encoded = json_encode($this->schema(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $lists = [
            'item_id',
            'skill_id',
            'employment_role_id',
            'independent_project_id',
            'role_or_project_id',
        ];

        foreach ($lists as $def) {
            $this->assertSame(1, substr_count($encoded, '"'.$def.'":'), $def);
        }

        $this->assertSame(1, substr_count($encoded, '"selected_item":'));
        $this->assertSame(2, substr_count($encoded, '"#/$defs/selected_item"'));
    }

    public function test_every_repeated_shape_is_defined_once(): void
    {
        $this->assertSame(
            [
                'requirement',
                'role_selection',
                'project_selection',
                'selected_item',
                'promotion',
                'employment_role_id',
                'independent_project_id',
                'item_id',
                'skill_id',
                'role_or_project_id',
                'headline_title',
                'role_mode',
            ],
            array_keys($this->defs()),
        );
    }

    /**
     * A `$ref` carries no sibling keywords. Not every reader of JSON Schema
     * takes them, and anything that needs saying about a referenced field is
     * said in the prompt instead.
     */
    public function test_a_reference_carries_nothing_beside_it(): void
    {
        $references = $this->references($this->schema());

        $this->assertNotSame([], $references);

        foreach ($references as $path => $reference) {
            $this->assertSame(['$ref'], array_keys($reference), $path);
        }
    }

    public function test_jd_analysis_is_the_first_field_the_model_reads(): void
    {
        $this->assertSame('jd_analysis', array_key_first($this->properties()));
        $this->assertSame('jd_analysis', $this->subArray($this->schema(), 'required')[0] ?? null);
    }

    /**
     * Structured outputs ask for both, and a field that may be absent is a
     * field the model can quietly decline to answer.
     */
    public function test_every_object_closes_itself_and_requires_all_of_its_properties(): void
    {
        $objects = $this->objects($this->schema());

        $this->assertNotSame([], $objects);

        foreach ($objects as $path => $object) {
            $this->assertFalse($object['additionalProperties'] ?? null, $path.': additionalProperties');

            $this->assertSame(
                array_keys($this->subArray($object, 'properties')),
                $object['required'] ?? null,
                $path.': required',
            );
        }
    }

    public function test_the_whole_selection_is_asked_for(): void
    {
        $this->assertSame(
            [
                'jd_analysis',
                'headline',
                'summary',
                'key_skills',
                'roles',
                'independent_projects',
                'review_notes',
            ],
            array_keys($this->properties()),
        );
    }

    /**
     * The two containers have shapes of their own rather than sharing one, so
     * the container decides the kind of thing it may hold. A project returned
     * under an employer claims commercial experience nobody paid for, and here
     * it cannot be returned at all.
     */
    public function test_a_project_cannot_be_returned_under_an_employer(): void
    {
        $this->assertSame(
            ['$ref' => '#/$defs/role_selection'],
            $this->subArray($this->properties(), 'roles', 'items'),
        );
        $this->assertSame(
            ['$ref' => '#/$defs/project_selection'],
            $this->subArray($this->properties(), 'independent_projects', 'items'),
        );

        $ids = SelectableIds::from($this->parseFixture());

        $this->assertSame([], array_intersect($ids->employmentRoles, $ids->independentProjects));
    }

    /**
     * The containers differ only in the kind of ID they take: selecting from a
     * project is selecting, and a second way of describing it would be a
     * second thing to keep in step.
     */
    public function test_the_two_containers_differ_only_in_the_id_they_take(): void
    {
        $role = $this->subArray($this->defs(), 'role_selection');
        $project = $this->subArray($this->defs(), 'project_selection');

        unset($role['properties'], $project['properties']);

        $this->assertSame($role, $project);
        $this->assertSame(
            array_keys($this->subArray($this->defs(), 'role_selection', 'properties')),
            array_keys($this->subArray($this->defs(), 'project_selection', 'properties')),
        );
    }

    public function test_a_selected_item_may_use_the_source_wording(): void
    {
        $text = $this->subArray($this->defs(), 'selected_item', 'properties', 'text');

        $this->assertSame(['string', 'null'], $text['type'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return (new OutputSchema)->for(SelectableIds::from($this->parseFixture()));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function defs(): array
    {
        return $this->subArray($this->schema(), '$defs');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function properties(): array
    {
        return $this->subArray($this->schema(), 'properties');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function enum(string $def): array
    {
        return $this->subArray($this->defs(), $def, 'enum');
    }

    /**
     * Every enum in the schema, by the path it sits at.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, list<string>>
     */
    private function enums(array $node, string $path = '$'): array
    {
        $found = [];

        if (is_array($node['enum'] ?? null)) {
            /** @var list<string> $values */
            $values = array_values($node['enum']);
            $found[$path] = $values;
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $found = [...$found, ...$this->enums($child, $path.'.'.$key)];
            }
        }

        return $found;
    }

    /**
     * Everything an enum in this schema is allowed to offer: the IDs that
     * belong to exactly one kind, plus the fixed vocabularies.
     *
     * @return list<string>
     */
    private function ofOneKind(SelectableIds $ids): array
    {
        return [
            ...$ids->items,
            ...$ids->skills,
            ...$ids->employmentRoles,
            ...$ids->independentProjects,
            ...$ids->headlineTitles,
            ...RoleMode::values(),
            ...RequirementPriority::values(),
            ...RequirementStatus::values(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function subArray(array $node, string|int ...$keys): array
    {
        foreach ($keys as $key) {
            $child = $node[$key] ?? null;

            $this->assertIsArray($child, sprintf('expected an array at "%s"', $key));

            $node = $child;
        }

        return $node;
    }

    /**
     * Every `$ref` in the schema, by the path it sits at.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, array<array-key, mixed>>
     */
    private function references(array $node, string $path = '$'): array
    {
        if (array_key_exists('$ref', $node)) {
            return [$path => $node];
        }

        $found = [];

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $found = [...$found, ...$this->references($child, $path.'.'.$key)];
            }
        }

        return $found;
    }

    /**
     * Every object schema within the schema, by the path it sits at.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<string, array<array-key, mixed>>
     */
    private function objects(array $node, string $path = '$'): array
    {
        $found = ($node['type'] ?? null) === 'object' ? [$path => $node] : [];

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $found = [...$found, ...$this->objects($child, $path.'.'.$key)];
            }
        }

        return $found;
    }
}
