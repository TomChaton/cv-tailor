<?php

namespace App\MasterCv;

use Illuminate\Filesystem\Filesystem;

/**
 * Brings a master CV that exists as a file into the application.
 *
 * The one thing that brings a master CV in from a path. `cv:preview` reads a
 * file too, for a fixture, but it stores nothing: this is the only route from
 * somewhere on disk to the CV the application generates from. It is how
 * somebody who has been keeping their CV in an editor stops having to, and it
 * is run once.
 *
 * It refuses a populated installation unless told otherwise, because the
 * stored CV may be the one that has since been edited, and a file sitting on
 * disk is no evidence that it is not.
 */
class ImportMasterCv
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ReadMasterCv $read,
        private readonly SaveMasterCv $save,
    ) {}

    /**
     * @throws MasterCvImportRefused
     */
    public function handle(string $path, bool $force = false): MasterCvReport
    {
        if (! $this->files->isFile($path) || ! $this->files->isReadable($path)) {
            throw MasterCvImportRefused::unreadable($path);
        }

        if (! $force && $this->read->stored() !== null) {
            throw MasterCvImportRefused::alreadyStored();
        }

        // An import is a save like any other: it lints, it logs
        // `master_cv.saved`, and a CV with problems in it still lands. Fixing
        // them is what the page is for.
        return $this->save->handle($this->files->get($path));
    }
}
