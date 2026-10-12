<?php

namespace Database\Factories;

use App\Models\MasterCvVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MasterCvVersion>
 */
class MasterCvVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Fictional, and varied per version so the hash is too: two versions
        // of a CV differ, and a factory that produced one hash for all of them
        // would model an index collision rather than a CV.
        $content = "# Fictional Person\n\n## Employment history\n\n### Example Ltd {#example}\n\n- Did a thing ".Str::random(8)." {#example.thing}\n";

        return [
            'hash' => hash('sha256', $content),
            'raw_content' => $content,
            // Nothing parses a master CV yet, and a guess at the parsed shape
            // here would read as a specification of it.
            'parsed_json' => [],
        ];
    }
}
