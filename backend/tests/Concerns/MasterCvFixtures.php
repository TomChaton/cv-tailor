<?php

namespace Tests\Concerns;

use App\MasterCv\DeriveSkillsFromTechStacks;
use App\MasterCv\MasterCvParser;
use App\MasterCv\ParsedMasterCv;
use App\MasterCv\RoleParser;
use App\MasterCv\SaveMasterCv;
use App\Models\MasterCv;

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

    /**
     * A fixture stored as the installation's master CV.
     *
     * Through the real save, because that is the only writer there is and a
     * test that went round it would be testing a row nothing else can produce.
     */
    protected function storeMasterCv(string $name = 'master-cv.md'): MasterCv
    {
        return $this->storeMarkdown($this->fixture($name));
    }

    protected function storeMarkdown(string $markdown): MasterCv
    {
        app(SaveMasterCv::class)->handle($markdown);

        return MasterCv::query()->sole();
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
