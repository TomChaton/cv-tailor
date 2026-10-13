<?php

namespace App\Rendering;

/**
 * The top of the page: who the CV is for and how to reach them.
 *
 * **Every field here comes from the master CV's parsed contact section.** None
 * of it is ever sent to the model, so none of it can come back altered: a model
 * cannot mistype a phone number it has never seen. That is what makes a wrong
 * contact detail impossible rather than unlikely.
 *
 * The headline is the one exception, and it is a choice between the CV's own
 * headline titles rather than free text.
 */
final readonly class ResolvedHeader
{
    /**
     * @param  list<array{label: string, value: string}>  $contact  The contact
     *                                                              section's
     *                                                              remaining
     *                                                              fields, in
     *                                                              the order
     *                                                              the author
     *                                                              wrote them.
     */
    public function __construct(
        public ?string $name,
        public ?string $headline,
        public array $contact,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'headline' => $this->headline,
            'contact' => $this->contact,
        ];
    }
}
