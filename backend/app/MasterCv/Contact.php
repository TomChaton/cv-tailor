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
     * Everything but the name, in the order the author wrote it.
     *
     * The author decided what belongs in their contact section, so nothing here
     * decides which of those fields is worth printing: a tool that ranked
     * contact labels would be inventing a taxonomy its author never wrote.
     *
     * @return array<string, string>
     */
    public function details(): array
    {
        return array_filter(
            $this->fields,
            static fn (string $value, string $label): bool => Markdown::normaliseHeading($label) !== 'name'
                && trim($value) !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
