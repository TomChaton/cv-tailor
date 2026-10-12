<?php

namespace App\MasterCv;

/**
 * A recognised top-level section of the master CV.
 *
 * Sections are found by heading rather than by position, and matching is
 * tolerant of a trailing parenthetical or dash qualifier: real headings carry
 * them ("Headline titles (any may be used)"), and a parser that insists on an
 * exact string fails on the file people actually write.
 */
enum Section: string
{
    case AgentRules = 'agent_rules';

    case Contact = 'contact';

    case HeadlineTitles = 'headline_titles';

    case ProfileFacts = 'profile_facts';

    case SkillsIndex = 'skills_index';

    case EmploymentHistory = 'employment_history';

    case IndependentProjects = 'independent_projects';

    case Awards = 'awards';

    case Interests = 'interests';

    case DoNotClaim = 'do_not_claim';

    public static function match(string $heading): ?self
    {
        $normalised = Markdown::normaliseHeading($heading);

        foreach (self::cases() as $section) {
            if (in_array($normalised, $section->headings(), true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The section's name as the CV's author would say it, for lint output.
     */
    public function label(): string
    {
        return match ($this) {
            self::AgentRules => 'Rules for any agent using this file',
            self::Contact => 'Contact',
            self::HeadlineTitles => 'Headline titles',
            self::ProfileFacts => 'Profile facts',
            self::SkillsIndex => 'Skills index',
            self::EmploymentHistory => 'Employment history',
            self::IndependentProjects => 'Independent projects',
            self::Awards => 'Awards',
            self::Interests => 'Personal interests',
            self::DoNotClaim => 'Do not claim',
        };
    }

    /**
     * A section a CV cannot be generated from without.
     *
     * Awards, interests and independent projects are things a person may not
     * have, so their absence is a fact about them rather than a defect in the
     * file.
     */
    public function isRequired(): bool
    {
        return match ($this) {
            self::Awards, self::Interests, self::IndependentProjects => false,
            default => true,
        };
    }

    /**
     * Accepted normalised spellings of this section's heading.
     *
     * @return list<string>
     */
    private function headings(): array
    {
        return match ($this) {
            self::AgentRules => ['rules for any agent using this file', 'rules for any agent', 'agent rules', 'rules'],
            self::Contact => ['contact', 'contact details'],
            self::HeadlineTitles => ['headline titles', 'headline', 'titles'],
            self::ProfileFacts => ['profile facts', 'profile'],
            self::SkillsIndex => ['skills index', 'skills'],
            self::EmploymentHistory => ['employment history', 'employment', 'experience', 'work history'],
            self::IndependentProjects => ['independent projects', 'personal projects', 'side projects', 'projects'],
            self::Awards => ['awards', 'awards and recognition'],
            self::Interests => ['personal interests', 'interests'],
            self::DoNotClaim => ['do not claim', "don't claim"],
        };
    }
}
