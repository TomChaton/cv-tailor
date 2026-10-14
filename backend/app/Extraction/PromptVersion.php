<?php

namespace App\Extraction;

/**
 * Which extraction prompt and output schema a posting's fields were read with.
 *
 * Extraction's own version, independent of generation's: the two prompts change
 * for different reasons, and a bump to one that moved the other would make
 * every revision look as though its prompt had changed.
 *
 * Bumped in the same commit as any change to the instructions, the page brief
 * or the extraction output schema.
 */
final class PromptVersion
{
    public const CURRENT = 'e1';
}
