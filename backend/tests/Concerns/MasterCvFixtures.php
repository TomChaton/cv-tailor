<?php

namespace Tests\Concerns;

use App\MasterCv\DeriveSkillsFromTechStacks;
use App\MasterCv\MasterCvParser;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\RoleParser;

/**
 * The fictional master CV, and the deliberately broken variants of it.
 *
 * Nothing in any fixture derives from a real CV.
 */
trait MasterCvFixtures
{
    protected function fixturePath(string $name = 'master-cv.md'): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }

    protected function fixture(string $name = 'master-cv.md'): string
    {
        return (string) file_get_contents($this->fixturePath($name));
    }

    protected function parseFixture(string $name = 'master-cv.md'): ParsedMasterCv
    {
        return $this->parser()->parse($this->fixture($name));
    }

    protected function parser(): MasterCvParser
    {
        return new MasterCvParser(new RoleParser, new DeriveSkillsFromTechStacks);
    }
}
