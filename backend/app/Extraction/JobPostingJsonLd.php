<?php

namespace App\Extraction;

/**
 * What a page states about itself, read from the `JobPosting` object it
 * published.
 *
 * The extension sends the object as the page wrote it and interprets none of
 * it: reading schema.org is a judgement about data, and judgements about a
 * capture are made here, where a better reading can be re-run over a posting
 * captured long ago.
 *
 * A board that publishes structured data is stating these facts, which is why
 * they win over anything a model inferred from the same page.
 */
final readonly class JobPostingJsonLd
{
    /**
     * @param  array<string, mixed>|null  $jsonLd
     * @return array<string, string|null> Keyed by column, for the fields a
     *                                    `JobPosting` states as data.
     */
    public function fields(?array $jsonLd): array
    {
        if ($jsonLd === null) {
            return [];
        }

        return [
            'title' => FieldText::column($jsonLd['title'] ?? null)
                ?? FieldText::column($jsonLd['name'] ?? null),
            'company' => $this->organisation($jsonLd['hiringOrganization'] ?? null),
            'location' => $this->place($jsonLd['jobLocation'] ?? null),
            'salary_text' => $this->salary($jsonLd['baseSalary'] ?? null),
        ];
    }

    private function organisation(mixed $value): ?string
    {
        if (self::isRecord($value)) {
            return FieldText::column($value['name'] ?? null);
        }

        return FieldText::column($value);
    }

    private function place(mixed $value): ?string
    {
        $place = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;

        if (! self::isRecord($place)) {
            return FieldText::column($place);
        }

        $address = $place['address'] ?? null;

        if (! self::isRecord($address)) {
            return FieldText::column($address) ?? FieldText::column($place['name'] ?? null);
        }

        $parts = [];

        foreach (['addressLocality', 'addressRegion', 'addressCountry'] as $key) {
            $part = $this->countryOrText($address[$key] ?? null);

            if ($part !== null) {
                $parts[] = $part;
            }
        }

        return $parts === []
            ? FieldText::column($place['name'] ?? null)
            : FieldText::column(implode(', ', $parts));
    }

    private function countryOrText(mixed $value): ?string
    {
        return self::isRecord($value)
            ? FieldText::column($value['name'] ?? null)
            : FieldText::column($value);
    }

    /**
     * A page that states a salary as a sentence has already written the text.
     * One that states a `MonetaryAmount` has not, so the parts are joined as
     * they stand: writing "per year" would put an English word in a data field,
     * and rounding or reformatting the numbers would change what the page said.
     */
    private function salary(mixed $value): ?string
    {
        if (! self::isRecord($value)) {
            return FieldText::column($value);
        }

        $amount = $value['value'] ?? null;
        $parts = [FieldText::column($value['currency'] ?? null)];

        if (self::isRecord($amount)) {
            $min = FieldText::column($amount['minValue'] ?? null);
            $max = FieldText::column($amount['maxValue'] ?? null);
            $single = FieldText::column($amount['value'] ?? null);

            $parts[] = $min !== null && $max !== null ? $min.'-'.$max : ($single ?? $min ?? $max);
            $parts[] = FieldText::column($amount['unitText'] ?? null);
        } else {
            $parts[] = FieldText::column($amount);
        }

        return FieldText::column(implode(' ', array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null,
        )));
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isRecord(mixed $value): bool
    {
        return is_array($value) && ! array_is_list($value);
    }
}
