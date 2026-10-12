<?php

namespace Tests\Unit\Generation;

use App\Generation\OutputSchema;
use App\Generation\SelectableIds;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class OutputSchemaTest extends TestCase
{
    use MasterCvFixtures;

    public function test_the_id_enum_is_the_cvs_own_ids(): void
    {
        $this->assertSame(
            SelectableIds::from($this->parseFixture())->ids,
            $this->enum('master_cv_id'),
        );
    }

    public function test_content_marked_todo_is_absent_from_the_id_enum(): void
    {
        $this->assertContains('larkspur-media.1', $this->enum('master_cv_id'));
        $this->assertNotContains('larkspur-media.2', $this->enum('master_cv_id'));
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
     * The ID list runs to a few hundred entries, so it sits in `$defs` once
     * and every field that takes an ID points at it.
     */
    public function test_the_id_list_is_defined_once_and_referenced_many_times(): void
    {
        $encoded = json_encode($this->schema(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame(1, substr_count($encoded, '"master_cv_id":'));
        $this->assertGreaterThan(5, substr_count($encoded, '"#/$defs/master_cv_id"'));
    }

    public function test_every_repeated_shape_is_defined_once(): void
    {
        $this->assertSame(
            [
                'requirement',
                'role_selection',
                'selected_item',
                'promotion',
                'master_cv_id',
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
     * Independent projects are selected exactly as roles are, so they share
     * the one definition rather than drifting from it.
     */
    public function test_independent_projects_share_the_role_shape(): void
    {
        $this->assertSame(
            $this->subArray($this->properties(), 'roles', 'items'),
            $this->subArray($this->properties(), 'independent_projects', 'items'),
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
