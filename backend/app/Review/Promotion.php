<?php

namespace App\Review;

use App\MasterCv\RoleMode;

/**
 * A role the model showed in more detail than the presentation policy's
 * default, and the reason it gave.
 *
 * The reason is the model's own words. The role is named from the master CV
 * version the revision is pinned to, because an ID is not a name.
 */
final readonly class Promotion
{
    public function __construct(
        public string $role,
        public ?RoleMode $from,
        public ?RoleMode $to,
        public string $reason,
    ) {}
}
