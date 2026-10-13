<?php

namespace App\MasterCv;

/**
 * Identity: the CV's contact section.
 *
 * **This never reaches the LLM.** The rendered CV's header is built from these
 * fields by the template, so the model cannot mistype a phone number it never
 * saw. It is kept in a class of its own rather than among the facts so that
 * leaking it takes a deliberate act rather than an oversight: see
 * `ParsedMasterCv::withoutIdentity()`.
 */
final readonly class Contact
{
    /**
     * @param  array<string, string>  $fields  Label as written, to value.
     */
    public function __construct(public array $fields) {}

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_values($this->fields);
    }

    /**
     * Who the CV is for.
     */
    public function name(): ?string
    {
        foreach ($this->fields as $label => $value) {
            if (Markdown::normaliseHeading($label) === 'name' && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * The fields the presentation policy names, in the order it names them.
     *
     * Which contact fields belong on a CV is the author's call and nobody
     * else's, so it is a policy setting rather than a judgement made here.
     *
     * **Labels are matched forgivingly of their exact wording**, the way
     * section headings are: a policy naming `LinkedIn` finds a field written
     * `LinkedIn profile`, and the reverse. **A named field the CV does not
     * have is skipped** — the policy is a preference about a header, not an
     * assertion about the file, and a CV without a phone number is a fact
     * about the person rather than a defect.
     *
     * @param  list<string>  $labels
     * @return array<string, string> Label as the author wrote it, to value.
     */
    public function only(array $labels): array
    {
        $headings = [];

        foreach ($this->fields as $label => $value) {
            if (trim($value) !== '') {
                $headings[$label] = Markdown::normaliseHeading($label);
            }
        }

        $fields = [];

        foreach ($labels as $wanted) {
            $label = self::closest(Markdown::normaliseHeading($wanted), $headings);

            if ($label !== null) {
                $fields[$label] = $this->fields[$label];
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $headings  Label as written, to its
     *                                           normalised form.
     */
    private static function closest(string $wanted, array $headings): ?string
    {
        foreach ($headings as $label => $heading) {
            if ($heading === $wanted) {
                return $label;
            }
        }

        foreach ($headings as $label => $heading) {
            if (str_starts_with($heading, $wanted.' ') || str_starts_with($wanted, $heading.' ')) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
