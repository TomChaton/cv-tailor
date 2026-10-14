<?php

namespace App\Extraction;

/**
 * What a posting says, and where each part of it came from.
 *
 * **Where the JSON-LD states a field, the JSON-LD wins.** A page that states
 * its salary has stated it, and a deterministic answer is never displaced by an
 * inferred one. The model is asked for every field regardless, so the log can
 * record which source filled each one and whether the two agreed.
 *
 * A field neither source gives stays null. The postings list shows a null as
 * absent, and guessing is what extraction replaces rather than what it
 * introduces.
 */
final readonly class ExtractedFields
{
    /**
     * The fields a `JobPosting` object states as data, and so the ones it wins.
     * Its `description` is a blob of the page's own markup, which would be a
     * second extraction rather than a stated fact, so the description is the
     * model's alone.
     *
     * @var list<string>
     */
    public const STATED = ['title', 'company', 'location', 'salary_text'];

    /** @var list<string> */
    public const ALL = ['title', 'company', 'location', 'salary_text', 'description'];

    /** What filled a field. */
    public const FROM_JSON_LD = 'json_ld';

    public const FROM_MODEL = 'model';

    /**
     * @param  array<string, string|null>  $values  Column to value.
     * @param  array<string, string|null>  $sources  Column to what filled it,
     *                                               and null where neither
     *                                               source did. Every column
     *                                               appears, so the shape of
     *                                               a log line does not
     *                                               depend on what a page
     *                                               happened to say.
     * @param  list<string>  $disagreements  Columns both sources stated,
     *                                       differently. Never the values: a
     *                                       log line does not carry the page's
     *                                       own text.
     */
    private function __construct(
        public array $values,
        public array $sources,
        public array $disagreements,
    ) {}

    /**
     * @param  array<string, string|null>  $model  The model's answer.
     * @param  array<string, string|null>  $stated  What the page's JSON-LD states.
     */
    public static function merge(array $model, array $stated): self
    {
        $values = [];
        $sources = [];
        $disagreements = [];

        foreach (self::ALL as $field) {
            $fromModel = $model[$field] ?? null;
            $fromPage = in_array($field, self::STATED, true) ? ($stated[$field] ?? null) : null;

            if ($fromPage !== null && $fromModel !== null && $fromPage !== $fromModel) {
                $disagreements[] = $field;
            }

            $values[$field] = $fromPage ?? $fromModel;

            $sources[$field] = match (true) {
                $fromPage !== null => self::FROM_JSON_LD,
                $fromModel !== null => self::FROM_MODEL,
                default => null,
            };
        }

        return new self($values, $sources, $disagreements);
    }
}
