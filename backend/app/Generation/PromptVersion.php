<?php

namespace App\Generation;

/**
 * Which prompt and output schema a revision was generated with.
 *
 * One constant, bumped in the same commit as any change to the static
 * instructions, the master CV block, the per-request brief or the output
 * schema. It is what lets a difference between two revisions be attributed to
 * the prompt rather than to the owner's instructions: without it, output that
 * changed shape has nothing to point at.
 */
final class PromptVersion
{
    public const CURRENT = 'g1';
}
