<?php

namespace App\MasterCv;

/**
 * Reads a master CV written in Markdown into rules, facts and identity.
 *
 * The CV is authored by hand and will be edited for years, so the parser bends
 * to the file rather than the other way round: sections are found by heading
 * and matched tolerantly, the skills index is accepted in either of the two
 * row forms people write, and an unrecognised block label is filed rather than
 * refused.
 */
class MasterCvParser
{
    public function __construct(
        private readonly RoleParser $roles,
        private readonly DeriveSkillsFromTechStacks $derive,
    ) {}

    public function parse(string $content): ParsedMasterCv
    {
        [$frontMatter, $firstBodyLine] = FrontMatter::split($content);
        $policy = PresentationPolicy::fromFrontMatter($frontMatter);

        $body = Markdown::lines($content);
        $body = array_values(array_filter(
            $body,
            static fn (Line $line): bool => $line->number >= $firstBodyLine,
        ));

        $agentRules = new RuleBlock('', []);
        $doNotClaim = new RuleBlock('', []);
        $contact = new Contact([]);
        $headlineTitles = [];
        $profileFacts = [];
        $skillGroups = [];
        $roles = [];
        $independentProjects = [];
        $awards = [];
        $interests = [];
        $sectionsPresent = [];

        foreach ($this->sections($body) as [$section, $lines]) {
            if (in_array($section, $sectionsPresent, true)) {
                continue;
            }

            $sectionsPresent[] = $section;

            match ($section) {
                Section::AgentRules => $agentRules = $this->ruleBlock($lines),
                Section::DoNotClaim => $doNotClaim = $this->ruleBlock($lines),
                Section::Contact => $contact = new Contact($this->labelledValues($lines)),
                Section::HeadlineTitles => $headlineTitles = $this->bullets($lines),
                Section::ProfileFacts => $profileFacts = $this->bullets($lines),
                Section::Awards => $awards = $this->bullets($lines),
                Section::Interests => $interests = $this->bullets($lines),
                Section::SkillsIndex => $skillGroups = $this->skillGroups($lines),
                Section::EmploymentHistory => $roles = $this->roles($lines, RoleKind::Employment, $policy),
                Section::IndependentProjects => $independentProjects = $this->roles($lines, RoleKind::Independent, $policy),
            };
        }

        // The index is completed from the stacks once the whole file is read:
        // either section may come first, and a technology's row depends on
        // both.
        $skillGroups = $this->derive->handle($skillGroups, [...$roles, ...$independentProjects]);

        return new ParsedMasterCv(
            frontMatter: $frontMatter,
            presentation: $policy,
            agentRules: $agentRules,
            doNotClaim: $doNotClaim,
            contact: $contact,
            headlineTitles: $headlineTitles,
            profileFacts: $profileFacts,
            skillGroups: $skillGroups,
            roles: $roles,
            independentProjects: $independentProjects,
            awards: $awards,
            interests: $interests,
            sectionsPresent: $sectionsPresent,
        );
    }

    /**
     * The recognised level-two sections, each with the lines beneath it.
     *
     * A heading this tool does not recognise closes the section above it: its
     * content belongs to neither, and silently filing it under the previous
     * heading would hand the model facts the author filed somewhere else.
     *
     * @param  list<Line>  $body
     * @return list<array{0: Section, 1: list<Line>}>
     */
    private function sections(array $body): array
    {
        $sections = [];
        $current = null;
        $lines = [];

        foreach ($body as $line) {
            $heading = Markdown::heading($line->text, 2);

            if ($heading === null) {
                if ($current !== null) {
                    $lines[] = $line;
                }

                continue;
            }

            if ($current !== null) {
                $sections[] = [$current, $lines];
            }

            $current = Section::match($heading);
            $lines = [];
        }

        if ($current !== null) {
            $sections[] = [$current, $lines];
        }

        return $sections;
    }

    /**
     * @param  list<Line>  $lines
     * @return list<array{0: string, 1: int, 2: list<Line>}> Heading, its line,
     *                                                       and the lines
     *                                                       under it.
     */
    private function subsections(array $lines): array
    {
        $subsections = [];
        $heading = null;
        $headingLine = 0;
        $collected = [];

        foreach ($lines as $line) {
            $next = Markdown::heading($line->text, 3);

            if ($next === null) {
                if ($heading !== null) {
                    $collected[] = $line;
                }

                continue;
            }

            if ($heading !== null) {
                $subsections[] = [$heading, $headingLine, $collected];
            }

            $heading = $next;
            $headingLine = $line->number;
            $collected = [];
        }

        if ($heading !== null) {
            $subsections[] = [$heading, $headingLine, $collected];
        }

        return $subsections;
    }

    /**
     * @param  list<Line>  $lines
     */
    private function ruleBlock(array $lines): RuleBlock
    {
        $raw = [];
        $items = [];

        foreach ($lines as $line) {
            if (trim($line->text) === '---') {
                continue;
            }

            $raw[] = $line->text;
            $bullet = Markdown::bullet($line->text);

            if ($bullet !== null) {
                $items[] = $bullet['text'];

                continue;
            }

            if (preg_match('/^\s*\d+[a-z]?\.\s+(?<item>.*)$/u', $line->text, $matches) === 1) {
                $items[] = trim($matches['item']);
            }
        }

        return new RuleBlock(trim(implode("\n", $raw)), $items);
    }

    /**
     * @param  list<Line>  $lines
     * @return list<string>
     */
    private function bullets(array $lines): array
    {
        $bullets = [];

        foreach ($lines as $line) {
            $bullet = Markdown::bullet($line->text);

            if ($bullet !== null) {
                $bullets[] = $bullet['text'];
            }
        }

        return $bullets;
    }

    /**
     * @param  list<Line>  $lines
     * @return array<string, string>
     */
    private function labelledValues(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            $bullet = Markdown::bullet($line->text);

            if ($bullet === null) {
                continue;
            }

            if (preg_match(Markdown::LABELLED_VALUE, $bullet['text'], $matches) === 1) {
                $values[trim($matches['label'])] = trim($matches['value']);
            }
        }

        return $values;
    }

    /**
     * @param  list<Line>  $lines
     * @return list<SkillGroup>
     */
    private function skillGroups(array $lines): array
    {
        $groups = [];

        foreach ($this->subsections($lines) as [$heading, $headingLine, $groupLines]) {
            $groups[] = new SkillGroup($heading, $headingLine, $this->skills($groupLines));
        }

        return $groups;
    }

    /**
     * Rows in either of the two forms the format uses: a two-column table of
     * skill and where it was used, or a plain bullet list. Both appear in
     * practice, so both are supported.
     *
     * @param  list<Line>  $lines
     * @return list<Skill>
     */
    private function skills(array $lines): array
    {
        $skills = [];

        foreach ($lines as $line) {
            if (Markdown::isTableSeparator($line->text)) {
                // The row above a separator is the table's header, not a skill.
                array_pop($skills);

                continue;
            }

            $cells = Markdown::tableRow($line->text);

            if ($cells !== null && $cells !== []) {
                $name = trim($cells[0]);
                $whereUsed = trim($cells[1] ?? '');

                if ($name !== '') {
                    $skills[] = Skill::make($name, $whereUsed === '' ? null : $whereUsed, $line->number);
                }

                continue;
            }

            $bullet = Markdown::bullet($line->text);

            if ($bullet === null) {
                continue;
            }

            // The list form puts where it was used after an em dash, and often
            // says nothing about it at all.
            [$name, $whereUsed] = $this->splitListRow($bullet['text']);
            $skills[] = Skill::make($name, $whereUsed, $line->number);
        }

        return $skills;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitListRow(string $text): array
    {
        foreach ([' — ', ' – '] as $separator) {
            if (str_contains($text, $separator)) {
                $parts = explode($separator, $text, 2);

                return [trim($parts[0]), trim($parts[1])];
            }
        }

        return [trim($text), null];
    }

    /**
     * @param  list<Line>  $lines
     * @return list<Role>
     */
    private function roles(array $lines, RoleKind $kind, PresentationPolicy $policy): array
    {
        $roles = [];

        foreach ($this->subsections($lines) as [$heading, $headingLine, $roleLines]) {
            $roles[] = $this->roles->parse($heading, $headingLine, $roleLines, $kind, $policy);
        }

        return $roles;
    }
}
