<?php

namespace App\MasterCv;

/**
 * Settings generation and rendering need that the CV's facts do not hold: how
 * long the tailored CV should be, how many skills it leads with, which roles
 * are condensed or dropped by default, which headline titles are on offer,
 * which contact fields the header carries, and whether awards and interests
 * are shown.
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
     * How many skills a tailored CV may name when the policy does not say.
     *
     * A sensible number rather than a considered one — the author changes it.
     * A CV that says nothing has to keep working, and a key skills list
     * running to most of the index is not a selection.
     */
    public const DEFAULT_MAX_KEY_SKILLS = 12;

    /**
     * The contact fields a CV header carries when the policy names none.
     *
     * A contact section holds everything an application needs — a postal
     * address, a right to work, a notice period — and a CV header is not where
     * those belong. The author's own labels often say as much ("use only if a
     * form requires it"), so the default prints the four a header has always
     * carried and leaves the rest for the form that asks. The name is the
     * document's title and is not one of them.
     *
     * @var list<string>
     */
    public const DEFAULT_CONTACT_FIELDS = ['Location', 'Phone', 'Email', 'LinkedIn'];

    /**
     * @param  int  $maxKeySkills  The most skills the output schema lets the
     *                             model name. A number larger than the
     *                             skills index holds is no cap in practice.
     * @param  ?list<string>  $headlineTitles  Null means every title in the
     *                                         CV's headline titles section is
     *                                         on offer.
     * @param  list<string>  $contactFields  Contact labels, in the order the
     *                                       header prints them.
     */
    public function __construct(
        public int $targetPages = self::DEFAULT_TARGET_PAGES,
        public int $maxKeySkills = self::DEFAULT_MAX_KEY_SKILLS,
        public ?int $condenseRolesEndingBefore = null,
        public ?int $omitRolesEndingBefore = null,
        public ?array $headlineTitles = null,
        public array $contactFields = self::DEFAULT_CONTACT_FIELDS,
        public bool $includeAwards = true,
        public bool $includeInterests = true,
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
            maxKeySkills: self::positiveInteger($policy, 'max_key_skills') ?? self::DEFAULT_MAX_KEY_SKILLS,
            condenseRolesEndingBefore: self::integer($policy, 'condense_roles_ending_before'),
            omitRolesEndingBefore: self::integer($policy, 'omit_roles_ending_before'),
            headlineTitles: self::titles($policy),
            contactFields: self::strings($policy, 'contact_fields') ?? self::DEFAULT_CONTACT_FIELDS,
            includeAwards: self::shows($policy, 'include_awards'),
            includeInterests: self::shows($policy, 'include_interests'),
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
            'max_key_skills' => $this->maxKeySkills,
            'condense_roles_ending_before' => $this->condenseRolesEndingBefore,
            'omit_roles_ending_before' => $this->omitRolesEndingBefore,
            'headline_titles' => $this->headlineTitles,
            'contact_fields' => $this->contactFields,
            'include_awards' => $this->includeAwards,
            'include_interests' => $this->includeInterests,
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
     * A count, which zero and below are not.
     *
     * A value that is not one reads as having said nothing at all, which is
     * what an absent key means too, so the default stands. The alternative is
     * a lint failure over a number nobody has to write, and a CV capped at
     * nothing names no skills at all.
     *
     * @param  array<array-key, mixed>  $policy
     */
    private static function positiveInteger(array $policy, string $key): ?int
    {
        $value = self::integer($policy, $key);

        return $value !== null && $value > 0 ? $value : null;
    }

    /**
     * A section the author wrote is shown unless they have switched it off.
     *
     * Anything but an explicit no reads as yes. The output schema has no field
     * for awards or interests, so the model cannot drop one; the only thing
     * that can is this key, and a misread value that quietly removed a section
     * the author wrote would be the worse error of the two.
     *
     * @param  array<array-key, mixed>  $policy
     */
    private static function shows(array $policy, string $key): bool
    {
        $value = $policy[$key] ?? null;

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['false', 'no', 'off'], true);
        }

        return $value !== false;
    }

    /**
     * @param  array<array-key, mixed>  $policy
     * @return ?list<string> Null when the key says nothing, so that the
     *                       caller's own default stands.
     */
    private static function titles(array $policy): ?array
    {
        return self::strings($policy, 'headline_titles');
    }

    /**
     * @param  array<array-key, mixed>  $policy
     * @return ?list<string>
     */
    private static function strings(array $policy, string $key): ?array
    {
        $values = $policy[$key] ?? null;

        if (! is_array($values)) {
            return null;
        }

        $strings = array_values(array_filter($values, is_string(...)));

        // A list that names nothing usable reads as having said nothing at
        // all, which is what an absent key means too.
        return $strings === [] ? null : $strings;
    }
}
