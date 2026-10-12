<?php

namespace App\Generation;

use App\MasterCv\RoleMode;

/**
 * One role whose returned mode is not one the model was free to choose.
 */
final readonly class RoleModeFinding
{
    public function __construct(
        public string $roleId,
        public RoleMode $default,
        public RoleMode $returned,
        public RoleModeFindingKind $kind,
    ) {}
}
