<?php

namespace App\Llm;

/**
 * How one request within a batch ended.
 *
 * The provider's four outcomes, passed through unchanged in meaning and in
 * spelling: flattening them would leave the batch lifecycle unable to tell a
 * batch that expired from one that was cancelled.
 */
enum BatchOutcome: string
{
    case Succeeded = 'succeeded';
    case Errored = 'errored';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
