<?php

namespace App\MasterCv;

use Illuminate\Support\Str;

/**
 * One row of the skills index.
 *
 * Its ID is derived from a slug of its name, unlike every other ID in the
 * document, which is hand-written. A skill is identified by its name —
 * renaming one is changing which skill it is — and deriving it keeps a few
 * hundred units out of the set the author has to maintain by hand. The cost is
 * bounded and accepted: reword a skill and an old revision cites an ID that no
 * longer resolves, the validators say so, and rendered PDFs are untouched.
 */
final readonly class Skill
{
    /**
     * @param  list<ContentMarker>  $markers
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $whereUsed,
        public int $line,
        public bool $selectable,
        public bool $verbatimOnly,
        public array $markers,
    ) {}

    public static function make(string $name, ?string $whereUsed, int $line): self
    {
        $markers = ContentMarker::detect($name.' '.($whereUsed ?? ''));

        return new self(
            id: self::idFor($name),
            name: $name,
            whereUsed: $whereUsed,
            line: $line,
            selectable: ! in_array(ContentMarker::Todo, $markers, true),
            verbatimOnly: in_array(ContentMarker::DoNotElaborate, $markers, true),
            markers: $markers,
        );
    }

    public static function idFor(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'where_used' => $this->whereUsed,
            'line' => $this->line,
            'selectable' => $this->selectable,
            'verbatim_only' => $this->verbatimOnly,
            'markers' => ContentMarker::values($this->markers),
        ];
    }
}
