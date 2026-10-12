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
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
