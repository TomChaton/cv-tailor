<?php

namespace App\MasterCv;

/**
 * Parses one role or independent project.
 *
 * **The label above a bullet is what the bullet means.** The same sentence is
 * inventory under "Tech stack", prose under "Context", and a selectable
 * achievement under anything else. A label this tool does not know is treated
 * as an achievement container and reported by lint, never rejected: a format
 * that refuses a heading its author invented is a format its author stops
 * maintaining.
 *
 * **An ID is what makes a unit selectable**, and that is also what tells an
 * achievement apart from a metadata field, which never carries one.
 */
class RoleParser
{
    private const CONTEXT = 'context';

    private const TECH_STACK = 'tech stack';

    /**
     * The labels this tool knows, written the way a note names them to a
     * reader who would rather use one than invent their own.
     *
     * Spellings it also accepts are deliberately not among them: a reader
     * asking what the alternatives are wants the alternatives, not a list of
     * synonyms for one of them.
     */
    public const RECOGNISED_LABELS = [
        'Context',
        'Tech stack',
        'Achievements & projects',
        'Responsibilities',
        'Projects',
    ];

    /**
     * Labels whose bullets are achievements and which this tool expects. Any
     * other label is also an achievement container, and additionally reported.
     */
    private const ACHIEVEMENT_LABELS = [
        'achievements & projects',
        'achievements and projects',
        'achievements',
        'responsibilities',
        'projects',
    ];

    /**
     * What bullets sitting under no label at all are filed as.
     */
    private const NO_LABEL = 'Achievements';

    /**
     * @param  list<Line>  $lines  The role's lines, excluding its heading.
     */
    public function parse(
        string $heading,
        int $headingLine,
        array $lines,
        RoleKind $kind,
        PresentationPolicy $policy,
    ): Role {
        [$headingText, $id] = Markdown::splitId($heading);
        [$employer, $title] = $this->splitHeading($headingText);

        /** @var array<string, string> $metadata */
        $metadata = [];
        /** @var list<TechStackEntry> $techStack */
        $techStack = [];
        /** @var list<array{label: string, line: int}> $unknownBlocks */
        $unknownBlocks = [];
        /** @var list<string> $context */
        $context = [];
        /** @var list<array{id: ?string, text: string, line: int, block: string, indent: int, parent: ?int}> $nodes */
        $nodes = [];
        /** @var array<int, int> $openByIndent */
        $openByIndent = [];

        $block = self::NO_LABEL;
        $inMetadata = true;

        foreach ($lines as $line) {
            $text = trim($line->text);

            if ($text === '' || $text === '---') {
                continue;
            }

            if (preg_match(Markdown::BLOCK_LABEL, $text, $matches) === 1) {
                $block = trim($matches['label']);
                $inMetadata = false;
                $openByIndent = [];
                $normalised = Markdown::normaliseHeading($block);

                if ($normalised !== self::CONTEXT
                    && $normalised !== self::TECH_STACK
                    && ! in_array($normalised, self::ACHIEVEMENT_LABELS, true)) {
                    $unknownBlocks[] = ['label' => $block, 'line' => $line->number];
                }

                continue;
            }

            $normalisedBlock = Markdown::normaliseHeading($block);
            $bullet = Markdown::bullet($line->text);
            $body = $bullet['text'] ?? $text;

            // An ID is what makes a unit selectable, and a metadata field
            // never carries one. So a labelled bullet that has an ID is an
            // achievement whose text opens with a bold lead-in — ordinary CV
            // writing — and not a field whose name happens to be a sentence.
            $isSelectableUnit = $bullet !== null && preg_match(Markdown::ID, $body) === 1;

            // A bold label with a value on the same line is metadata wherever
            // it appears — as a bullet in the block above the first label, or
            // as a standalone line between blocks.
            if (! $isSelectableUnit
                && preg_match(Markdown::LABELLED_VALUE, $body, $matches) === 1
                && ($inMetadata || $bullet === null)) {
                $label = trim($matches['label']);
                $value = trim($matches['value']);

                if (Markdown::normaliseHeading($label) === self::TECH_STACK) {
                    $techStack[] = new TechStackEntry(null, Markdown::splitList($value), $line->number);

                    continue;
                }

                $metadata[$label] = $value;

                continue;
            }

            if ($normalisedBlock === self::TECH_STACK) {
                $techStack[] = $this->techStackEntry($body, $line->number);

                continue;
            }

            if ($normalisedBlock === self::CONTEXT) {
                $context[] = $body;

                continue;
            }

            if ($bullet === null) {
                // A continuation of the bullet above it, which is the only
                // thing a loose line under an achievement block can be.
                $last = array_key_last($nodes);

                if ($last !== null) {
                    $nodes[$last]['text'] = trim($nodes[$last]['text'].' '.$text);
                }

                continue;
            }

            $indent = $bullet['indent'];

            $parent = null;

            foreach ($openByIndent as $openIndent => $index) {
                if ($openIndent < $indent) {
                    $parent = $index;
                }
            }

            foreach (array_keys($openByIndent) as $openIndent) {
                if ($openIndent >= $indent) {
                    unset($openByIndent[$openIndent]);
                }
            }

            $nodes[] = [
                // Still carrying its ID attribute: see below.
                'id' => null,
                'text' => $bullet['text'],
                'line' => $line->number,
                'block' => $block,
                'indent' => $indent,
                'parent' => $parent,
            ];

            $openByIndent[$indent] = array_key_last($nodes) ?? 0;
            ksort($openByIndent);
        }

        // The ID is split off only now that every continuation line has been
        // joined onto the bullet it continues, so a bullet the author wrapped
        // — carrying its `{#id}` onto the second line — reads as an unwrapped
        // one does. Splitting per line would leave the ID buried in the text
        // and the unit with none, and nothing downstream could tell the
        // difference between that and an ID the author never wrote.
        foreach ($nodes as $index => $node) {
            [$nodes[$index]['text'], $nodes[$index]['id']] = Markdown::splitId($node['text']);
        }

        $dates = $this->dates($metadata);

        return new Role(
            id: $id,
            kind: $kind,
            heading: $headingText,
            employer: $employer,
            title: $title,
            line: $headingLine,
            metadata: $metadata,
            dates: $dates,
            defaultMode: $policy->defaultModeFor($dates),
            context: $context === [] ? null : implode("\n", $context),
            techStack: $techStack,
            achievements: $this->achievements($nodes, null, true, false),
            unknownBlocks: $unknownBlocks,
        );
    }

    /**
     * @param  list<array{id: ?string, text: string, line: int, block: string, indent: int, parent: ?int}>  $nodes
     * @return list<Achievement>
     */
    private function achievements(array $nodes, ?int $parent, bool $parentSelectable, bool $parentVerbatimOnly): array
    {
        $achievements = [];

        foreach ($nodes as $index => $node) {
            if ($node['parent'] !== $parent) {
                continue;
            }

            $markers = ContentMarker::detect($node['text']);

            // Inherited downwards: a sub-bullet of a bullet the author marked
            // `TODO` is as unknown as its parent, and a child of a bullet that
            // may only be used verbatim cannot be reworded either.
            $selectable = $parentSelectable && ! in_array(ContentMarker::Todo, $markers, true);
            $verbatimOnly = $parentVerbatimOnly || in_array(ContentMarker::DoNotElaborate, $markers, true);

            $achievements[] = new Achievement(
                id: $node['id'],
                text: $node['text'],
                line: $node['line'],
                blockLabel: $node['block'],
                selectable: $selectable,
                verbatimOnly: $verbatimOnly,
                markers: $markers,
                children: $this->achievements($nodes, $index, $selectable, $verbatimOnly),
            );
        }

        return $achievements;
    }

    private function techStackEntry(string $body, int $number): TechStackEntry
    {
        // A space after the colon is required so that a single technology
        // whose name contains one (`JSON:API`) is not read as a category.
        if (preg_match('/^(?<category>[^:]{1,40}):\s+(?<value>.+)$/u', $body, $matches) === 1) {
            return new TechStackEntry(
                trim($matches['category']),
                Markdown::splitList($matches['value']),
                $number,
            );
        }

        return new TechStackEntry(null, Markdown::splitList($body), $number);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function dates(array $metadata): ?DateRange
    {
        foreach ($metadata as $label => $value) {
            if (in_array(Markdown::normaliseHeading($label), ['dates', 'date'], true)) {
                return DateRange::parse($value);
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string} Employer and title.
     */
    private function splitHeading(string $heading): array
    {
        foreach ([' — ', ' – ', ' - '] as $separator) {
            if (str_contains($heading, $separator)) {
                $parts = explode($separator, $heading, 2);

                return [trim($parts[0]), trim($parts[1])];
            }
        }

        return [trim($heading), null];
    }
}
