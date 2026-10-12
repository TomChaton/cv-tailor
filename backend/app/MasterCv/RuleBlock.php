<?php

namespace App\MasterCv;

/**
 * Rules: the agent rules section and "Do not claim".
 *
 * Both go to the model word for word, so the raw text is kept exactly as the
 * author wrote it. The items are kept as well because the deterministic
 * checks work line by line.
 */
final readonly class RuleBlock
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(
        public string $raw,
        public array $items,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'items' => $this->items,
        ];
    }
}
