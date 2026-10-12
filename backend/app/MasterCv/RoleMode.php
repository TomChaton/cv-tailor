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

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * How much of a role this mode shows, as an order.
     *
     * The permitted direction of travel is the whole point of the enum, so the
     * one place that knows which mode shows more is the enum itself.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Omitted => 0,
            self::Condensed => 1,
            self::Full => 2,
        };
    }

    public function isPromotionOf(self $default): bool
    {
        return $this->rank() > $default->rank();
    }

    public function isDemotionOf(self $default): bool
    {
        return $this->rank() < $default->rank();
    }
}
