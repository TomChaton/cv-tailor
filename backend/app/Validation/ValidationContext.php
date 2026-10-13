<?php

namespace App\Validation;

use App\MasterCv\ParsedMasterCv;
use App\Validation\Output\Selection;

/**
 * One revision's output beside the master CV version it was generated from,
 * for the checks to read.
 *
 * It holds the raw output as well as the read one. Most checks want the
 * selection's shape; the "Do not claim" check wants every word that came
 * back, whatever field it arrived in, and a check that walked a typed shape
 * would stop seeing a field the day one is added.
 *
 * The indexes and the vocabulary are built once here rather than per check:
 * nine checks over a few hundred IDs is not expensive, but nine rebuilds of
 * the same index is nine chances for two checks to disagree about what the CV
 * says.
 */
final readonly class ValidationContext
{
    /**
     * @param  array<string, mixed>  $output
     */
    private function __construct(
        public ParsedMasterCv $cv,
        public array $output,
        public Selection $selection,
        public MasterCvIndex $index,
        public TechnologyVocabulary $technologies,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function for(ParsedMasterCv $cv, array $output): self
    {
        return new self(
            cv: $cv,
            output: $output,
            selection: Selection::from($output),
            index: new MasterCvIndex($cv),
            technologies: new TechnologyVocabulary($cv->allSkills()),
        );
    }
}
