<?php

namespace App\MasterCv;

/**
 * How much of a role a tailored CV shows.
 *
 * The model may promote a role above its default and never demote it, which is
 * why the presentation policy carries two cutoff years rather than one: with a
 * single cutoff, `Omitted` is unreachable as a default and every role appears
 * on every CV.
 */
enum RoleMode: string
{
    case Full = 'full';

    case Condensed = 'condensed';

    case Omitted = 'omitted';
}
