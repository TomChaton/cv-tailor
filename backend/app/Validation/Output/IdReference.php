<?php

namespace App\Validation\Output;

/**
 * One master CV ID as the output used it, with the field it was used in.
 *
 * The field travels with the ID because a finding has to tell the owner where
 * to look, and the same ID may be used correctly in one place and wrongly in
 * another.
 */
final readonly class IdReference
{
    public function __construct(
        public string $id,
        public string $field,
    ) {}
}
