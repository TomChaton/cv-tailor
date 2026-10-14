<?php

namespace App\Extraction;

/**
 * Fitting what a page says into the column that holds it.
 *
 * The title, company, location and salary columns are 255 characters, and
 * neither a model nor a page's own structured data is under any obligation to
 * respect that. A value past the limit is cut rather than refused: a posting
 * with a long location is still a posting, and refusing the capture over it
 * would lose the job.
 */
final readonly class FieldText
{
    public const LIMIT = 255;

    /**
     * One line, for a column that holds one.
     */
    public static function column(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($collapsed === '') {
            return null;
        }

        return mb_strlen($collapsed) > self::LIMIT
            ? mb_substr($collapsed, 0, self::LIMIT)
            : $collapsed;
    }

    /**
     * A body of prose, for a `text` column. Its line breaks are part of what it
     * says, so only the ends are trimmed.
     */
    public static function body(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
