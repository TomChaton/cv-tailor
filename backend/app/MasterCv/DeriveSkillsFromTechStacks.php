<?php

namespace App\MasterCv;

/**
 * Gives every technology in a tech stack a skills-index row.
 *
 * A technology named in a role's stack is already a statement that it was used
 * in that role, so a row saying so restates it — and two statements of one
 * fact drift. Deriving the row removes the disagreement instead of detecting
 * it, and widens the vocabulary the technology-to-role check is built from: a
 * technology in a stack and in no row was invisible to it.
 *
 * **A hand-written row wins.** Where the index already knows a technology,
 * that row stands unchanged and nothing is derived for it, so no existing CV
 * needs editing and the index stays the place to say what the derivation
 * cannot — a different name, a grouping, a mention of where it was used beyond
 * the stacks.
 */
class DeriveSkillsFromTechStacks
{
    /**
     * Where an entry on an unlabelled tech-stack line is filed. Developer
     * wording in parsed content, not screen copy: it is the heading of a group
     * the author never named.
     */
    private const CATCH_ALL = 'Other';

    /**
     * @param  list<SkillGroup>  $groups  The index as the author wrote it.
     * @param  list<Role>  $roles  Employment and independent projects together.
     * @return list<SkillGroup>
     */
    public function handle(array $groups, array $roles): array
    {
        $written = [];

        foreach ($groups as $group) {
            $written = [...$written, ...$group->skills];
        }

        $derived = $this->rows($written, $roles);

        if ($derived === []) {
            return $groups;
        }

        return $this->file($groups, $derived);
    }

    /**
     * One row per technology no hand-written row covers, in the order the
     * stacks name them.
     *
     * Rows are keyed on the ID a written row for the same name would get, so a
     * technology two roles spell the same way is one skill naming both, and a
     * derived row can never collide with another ID in the document. Two roles
     * spelling it differently are two skills, which the near-duplicate check
     * is there to point out.
     *
     * @param  list<Skill>  $written
     * @param  list<Role>  $roles
     * @return array<string, array{name: string, label: ?string, line: int, used: list<string>}>
     */
    private function rows(array $written, array $roles): array
    {
        $index = new SkillVocabulary($written);

        $taken = [];

        foreach ($written as $skill) {
            $taken[$skill->id] = true;
        }

        $rows = [];

        foreach ($roles as $role) {
            foreach ($role->techStack as $entry) {
                foreach ($entry->terms as $term) {
                    if ($index->covers($term)) {
                        continue;
                    }

                    $id = Skill::idFor($term);

                    if ($id === '' || isset($taken[$id])) {
                        continue;
                    }

                    $rows[$id] ??= [
                        'name' => $term,
                        'label' => $entry->category,
                        'line' => $entry->line,
                        'used' => [],
                    ];

                    $where = $role->whereUsed();

                    if (! in_array($where, $rows[$id]['used'], true)) {
                        $rows[$id]['used'][] = $where;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<SkillGroup>  $groups
     * @param  array<string, array{name: string, label: ?string, line: int, used: list<string>}>  $rows
     * @return list<SkillGroup>
     */
    private function file(array $groups, array $rows): array
    {
        /** @var array<string, int> $existing Group key to its place in the index. */
        $existing = [];

        foreach ($groups as $position => $group) {
            $existing[self::groupKey($group->heading)] ??= $position;
        }

        /** @var array<int, list<Skill>> $added Rows joining a group the author wrote. */
        $added = [];

        /** @var array<string, array{heading: string, line: int, skills: list<Skill>}> $opened */
        $opened = [];

        foreach ($rows as $row) {
            $skill = Skill::make($row['name'], implode(', ', $row['used']), $row['line'], derived: true);
            $heading = $row['label'] ?? self::CATCH_ALL;
            $key = self::groupKey($heading);

            if (isset($existing[$key])) {
                $added[$existing[$key]][] = $skill;

                continue;
            }

            // Where two labels mean one group, the first spelling names it.
            $opened[$key] ??= ['heading' => $heading, 'line' => $row['line'], 'skills' => []];
            $opened[$key]['skills'][] = $skill;
        }

        $filed = [];

        foreach ($groups as $position => $group) {
            $filed[] = isset($added[$position])
                ? new SkillGroup($group->heading, $group->line, [...$group->skills, ...$added[$position]])
                : $group;
        }

        foreach ($opened as $group) {
            $filed[] = new SkillGroup($group['heading'], $group['line'], $group['skills']);
        }

        return $filed;
    }

    /**
     * What makes two groupings one.
     *
     * Case, punctuation and a qualifier after a slash or a dash are not a
     * grouping decision: `Data`, `data` and `Data / search` obviously mean one
     * shelf, and a tech-stack label that matches a heading the author already
     * wrote joins it rather than opening a second one beside it. Where two
     * labels genuinely differ they stay apart.
     */
    private static function groupKey(string $heading): string
    {
        // A qualifier after a slash goes the way `normaliseHeading` sends one
        // after a dash.
        $heading = (string) preg_replace('#\s*/.*$#u', '', $heading);
        $heading = Markdown::normaliseHeading($heading);
        $heading = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $heading);

        return trim((string) preg_replace('/\s+/u', ' ', $heading));
    }
}
