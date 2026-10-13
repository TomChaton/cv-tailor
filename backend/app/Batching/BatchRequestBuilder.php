<?php

namespace App\Batching;

use App\Generation\GenerationIsRefused;
use App\Llm\BatchRequest;
use App\Models\Revision;

/**
 * Turns a revision into the request that generates it.
 *
 * The batch lifecycle moves revisions and batches between states and knows
 * nothing about prompts; generation knows about prompts and nothing about
 * batches. This is where the two meet, so neither has to read the other.
 *
 * The request's custom ID is the revision's ID, because the provider returns a
 * batch's results in any order and that ID is what a result is matched by.
 */
interface BatchRequestBuilder
{
    /**
     * @throws GenerationIsRefused When the revision must not
     *                             be sent to the model at all.
     */
    public function batchRequestFor(Revision $revision): BatchRequest;
}
