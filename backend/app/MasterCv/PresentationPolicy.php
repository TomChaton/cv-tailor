<?php

namespace App\MasterCv;

/**
 * Settings generation needs that the CV's facts do not hold: how long the
 * tailored CV should be, which roles are condensed or dropped by default, and
 * which headline titles are on offer.
 *
 * It lives in the CV's front matter so each author's policy travels with
 * their CV. In practice the keys are usually absent, so the defaults here are
 * the normal path rather than a fallback, and absence is never a lint failure.
 *
 * With no cutoff years configured every role defaults to `Full`. That is the
 * honest reading of an author who has said nothing about length, and the
 * safest: the model may promote a role above its default, never below it, so a
 * default of `Full` can never hide a role the author wanted shown.
 */
final readonly class PresentationPolicy
{
    public const DEFAULT_TARGET_PAGES = 2;

    /**
     * @param  ?list<string>  $headlineTitles  Null means every title in the
     *                                         CV's headline titles section is
     *                                         on offer.
     */
    public function __construct(
        public int $targetPages = self::DEFAULT_TARGET_PAGES,
        public ?int $condenseRolesEndingBefore = null,
        public ?int $omitRolesEndingBefore = null,
        public ?array $headlineTitles = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $frontMatter
     */
    public static function fromFrontMatter(array $frontMatter): self
    {
        $policy = $frontMatter['presentation'] ?? null;

        if (! is_array($policy)) {
            return new self;
        }

        return new self(
            targetPages: self::integer($policy, 'target_pages') ?? self::DEFAULT_TARGET_PAGES,
            condenseRolesEndingBefore: self::integer($policy, 'condense_roles_ending_before'),
            omitRolesEndingBefore: self::integer($policy, 'omit_roles_ending_before'),
            headlineTitles: self::titles($policy),
        );
    }

    public function defaultModeFor(?DateRange $dates): RoleMode
    {
        $endYear = $dates?->endYear;

        if ($endYear === null) {
            return RoleMode::Full;
        }

        if ($this->omitRolesEndingBefore !== null && $endYear < $this->omitRolesEndingBefore) {
            return RoleMode::Omitted;
        }

        if ($this->condenseRolesEndingBefore !== null && $endYear < $this->condenseRolesEndingBefore) {
            return RoleMode::Condensed;
        }

        return RoleMode::Full;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_pages' => $this->targetPages,
            'condense_roles_ending_before' => $this->condenseRolesEndingBefore,
            'omit_roles_ending_before' => $this->omitRolesEndingBefore,
            'headline_titles' => $this->headlineTitles,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $policy
     */
    private static function integer(array $policy, string $key): ?int
    {
        $value = $policy[$key] ?? null;

        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * @param  array<array-key, mixed>  $policy
     * @return ?list<string>
     */
    private static function titles(array $policy): ?array
    {
        $titles = $policy['headline_titles'] ?? null;

        if (! is_array($titles)) {
            return null;
        }

        $strings = array_values(array_filter($titles, is_string(...)));

        return $strings === [] ? null : $strings;
    }
}
