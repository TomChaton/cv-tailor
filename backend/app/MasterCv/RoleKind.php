<?php

namespace App\MasterCv;

/**
 * Whether an entry is employment or an independent project.
 *
 * The two parse identically and are kept apart deliberately: content from an
 * independent project must never appear under an employer, and a tailored CV
 * that blurs the two claims commercial experience nobody paid for.
 */
enum RoleKind: string
{
    case Employment = 'employment';

    case Independent = 'independent';
}
