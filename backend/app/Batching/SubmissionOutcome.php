<?php

namespace App\Batching;

use App\Llm\BatchOutcome;

/**
 * What the provider made of one request in one batch.
 *
 * `awaiting` is the state a submission is written in and the only one a result
 * may be applied from. The other four are the provider's own outcomes, and a
 * row carrying one of them has been dealt with: that is what makes collecting
 * a batch resumable.
 */
enum SubmissionOutcome: string
{
    case Awaiting = 'awaiting';

    case Succeeded = 'succeeded';

    case Errored = 'errored';

    case Expired = 'expired';

    case Canceled = 'canceled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The four are spelled the same on both sides. They are mapped by hand all
     * the same, so that a fifth outcome on either side is a match error here
     * rather than a row with an outcome nothing reads.
     */
    public static function fromResult(BatchOutcome $outcome): self
    {
        return match ($outcome) {
            BatchOutcome::Succeeded => self::Succeeded,
            BatchOutcome::Errored => self::Errored,
            BatchOutcome::Expired => self::Expired,
            BatchOutcome::Canceled => self::Canceled,
        };
    }
}
