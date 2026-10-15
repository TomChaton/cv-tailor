<?php

namespace App\Revisions;

/**
 * Where one tailored CV has got to.
 */
enum RevisionStatus: string
{
    case Pending = 'pending';

    case Submitted = 'submitted';

    case Generated = 'generated';

    case Validated = 'validated';

    case Ready = 'ready';

    case Failed = 'failed';

    /**
     * The owner stopped wanting this, which is not the same thing as `failed`.
     *
     * A failure is the model or the provider not delivering, and it spends an
     * attempt. This is a decision, it costs nothing, and it is never retried.
     * Conflating the two would put a decision in the failure counts and leave
     * the attempt limit meaning two things.
     */
    case Abandoned = 'abandoned';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A complete revision is never updated: an edit or a regeneration creates a
     * child instead. That is what makes a diff between two revisions mean
     * something.
     */
    public function isComplete(): bool
    {
        return match ($this) {
            self::Ready, self::Failed => true,
            default => false,
        };
    }
}
