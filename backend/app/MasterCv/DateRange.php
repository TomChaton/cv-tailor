<?php

namespace App\MasterCv;

/**
 * A role's dates, as the author wrote them and as years.
 *
 * Only the years are extracted. Months vary in spelling and separator from
 * one line to the next, and nothing in the system needs them: the rendered CV
 * prints `raw` and the presentation policy only asks which year a role ended.
 */
final readonly class DateRange
{
    public function __construct(
        public string $raw,
        public ?int $startYear,
        public ?int $endYear,
        public bool $current,
    ) {}

    public static function parse(string $raw): self
    {
        $raw = trim($raw);
        $current = preg_match('/\b(present|current|now|ongoing)\b/i', $raw) === 1;

        preg_match_all('/\b(?:19|20)\d{2}\b/', $raw, $matches);

        /** @var list<int> $years */
        $years = array_map(intval(...), $matches[0]);

        return new self(
            raw: $raw,
            startYear: $years === [] ? null : min($years),
            endYear: $current || $years === [] ? null : max($years),
            current: $current,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'start_year' => $this->startYear,
            'end_year' => $this->endYear,
            'current' => $this->current,
        ];
    }
}
