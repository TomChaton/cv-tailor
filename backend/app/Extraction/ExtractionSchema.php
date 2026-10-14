<?php

namespace App\Extraction;

/**
 * The JSON schema the model's answer about a page is constrained to.
 *
 * Unlike generation's, it is the same for every request: nothing in it is
 * derived from a master CV, because extraction never sees one. Any change to
 * it is a change to `PromptVersion`.
 *
 * Every field is nullable and every field is required: a page that does not
 * state its salary has to be able to say so, and a field that may be absent is
 * a field the model can quietly decline to answer.
 */
final readonly class ExtractionSchema
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ExtractedFields::ALL,
            'properties' => [
                'title' => self::nullableString("The job's own title, as the page states it."),
                'company' => self::nullableString('The employer the page names.'),
                'location' => self::nullableString('Where the work is, as the page states it.'),
                'salary_text' => self::nullableString('What the page says about pay, in its own words.'),
                'description' => self::nullableString(
                    'The job description itself, in the page\'s own words.',
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nullableString(string $description): array
    {
        return [
            'type' => ['string', 'null'],
            'description' => $description.' Null where the page does not state it.',
        ];
    }
}
