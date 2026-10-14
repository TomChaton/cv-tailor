<?php

namespace App\MasterCv\Lint;

/**
 * What puts a problem right, named rather than spelled out.
 *
 * A finding says what is wrong and never how to fix it. The same words are
 * read in a terminal and in the review UI, and a screen with a button for a
 * remedy does not want advice on running a command instead. So a check names
 * the remedy and each surface decides what, if anything, it has to say about
 * it: `cv:lint` prints the command, because a terminal has no button.
 *
 * Which means these cases carry no words. The surface owns those.
 */
enum Remedy
{
    case AddMissingIds;

    /**
     * Choose, from the roles and projects the document already holds, which
     * of them a skills-index row names.
     */
    case ChooseWhereUsed;
}
