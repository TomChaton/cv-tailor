<?php

namespace App\Rendering;

/**
 * One skill of the finished document.
 *
 * The model returns skill IDs and never skill names, so the name on the page is
 * always the author's own. "Where used" is left out: it is the evidence the
 * skills index carries for the author's benefit, not a line of a tailored CV.
 */
final readonly class ResolvedSkill
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
