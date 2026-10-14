<?php

namespace Tests\Unit\Extraction;

use App\Extraction\ExtractedFields;
use PHPUnit\Framework\TestCase;

class ExtractedFieldsTest extends TestCase
{
    public function test_the_model_fills_every_field_a_page_states_nothing_about(): void
    {
        $fields = ExtractedFields::merge($this->model(), []);

        $this->assertSame([
            'title' => 'Senior PHP Engineer',
            'company' => 'Northwind Systems',
            'location' => 'Leeds',
            'salary_text' => '£65,000 to £78,000',
            'description' => 'We run a clearing platform.',
        ], $fields->values);

        $this->assertSame([
            'title' => 'model',
            'company' => 'model',
            'location' => 'model',
            'salary_text' => 'model',
            'description' => 'model',
        ], $fields->sources);

        $this->assertSame([], $fields->disagreements);
    }

    /**
     * A page that states its salary has stated it, and a deterministic answer
     * is never displaced by an inferred one.
     */
    public function test_a_stated_field_wins_and_the_disagreement_is_recorded(): void
    {
        $fields = ExtractedFields::merge($this->model(), ['salary_text' => 'GBP 65000-78000 YEAR']);

        $this->assertSame('GBP 65000-78000 YEAR', $fields->values['salary_text']);
        $this->assertSame('json_ld', $fields->sources['salary_text']);
        $this->assertSame(['salary_text'], $fields->disagreements);
    }

    public function test_two_sources_that_agree_are_not_a_disagreement(): void
    {
        $fields = ExtractedFields::merge($this->model(), ['title' => 'Senior PHP Engineer']);

        $this->assertSame('json_ld', $fields->sources['title']);
        $this->assertSame([], $fields->disagreements);
    }

    public function test_a_field_neither_source_gives_is_null_and_has_no_source(): void
    {
        $fields = ExtractedFields::merge(['title' => 'Platform Engineer'], []);

        $this->assertSame('Platform Engineer', $fields->values['title']);
        $this->assertNull($fields->values['company']);
        $this->assertNull($fields->values['description']);
        $this->assertSame([
            'title' => 'model',
            'company' => null,
            'location' => null,
            'salary_text' => null,
            'description' => null,
        ], $fields->sources);
    }

    /**
     * Every column extraction fills appears, so a field the model declined to
     * answer is written as null rather than left as whatever was there.
     */
    public function test_every_field_is_answered_even_when_the_answer_is_nothing(): void
    {
        $fields = ExtractedFields::merge([], []);

        $this->assertSame(ExtractedFields::ALL, array_keys($fields->values));
        $this->assertSame([], array_filter(
            $fields->values,
            static fn (?string $value): bool => $value !== null,
        ));

        // Every field appears whatever filled it, so the shape of a log line
        // does not depend on what a page happened to say.
        $this->assertSame(ExtractedFields::ALL, array_keys($fields->sources));
        $this->assertSame([], array_filter(
            $fields->sources,
            static fn (?string $source): bool => $source !== null,
        ));
    }

    /**
     * A `JobPosting`'s own description is a blob of the page's markup, so
     * reading it would be a second extraction rather than a stated fact.
     */
    public function test_a_page_cannot_state_the_description(): void
    {
        $fields = ExtractedFields::merge($this->model(), [
            'description' => 'Apply through the portal.',
        ]);

        $this->assertSame('We run a clearing platform.', $fields->values['description']);
        $this->assertSame('model', $fields->sources['description']);
        $this->assertSame([], $fields->disagreements);
    }

    /**
     * @return array<string, string|null>
     */
    private function model(): array
    {
        return [
            'title' => 'Senior PHP Engineer',
            'company' => 'Northwind Systems',
            'location' => 'Leeds',
            'salary_text' => '£65,000 to £78,000',
            'description' => 'We run a clearing platform.',
        ];
    }
}
