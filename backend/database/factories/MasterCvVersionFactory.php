<?php

namespace Database\Factories;

use App\MasterCv\MasterCvParser;
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
        return $this->attributesFor(
            "# Fictional Person\n\n## Employment history\n\n### Example Ltd {#example}\n\n- Did a thing "
            .Str::random(8)." {#example.thing}\n"
        );
    }

    /**
     * A version holding a given CV, lint-worthy or not.
     *
     * The loader refuses to store a CV that fails lint, so anything that needs
     * to be handed a stored version of one makes it here.
     */
    public function content(string $raw): static
    {
        return $this->state($this->attributesFor($raw));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(string $raw): array
    {
        $parsed = app(MasterCvParser::class)->parse($raw);

        return [
            // The parse is what identifies a version, not the bytes it was
            // read from, so this hashes what the application hashes.
            'hash' => $parsed->hash(),
            'raw_content' => $raw,
            'parsed_json' => $parsed->toArray(),
        ];
    }
}
