<?php

namespace Tests\Concerns;

use Illuminate\Filesystem\Filesystem;

/**
 * A directory of this test's own, thrown away afterwards.
 *
 * For the cases that need a file somewhere writable: a master CV that is not
 * there, one that is a directory, one that changes between two reads. None of
 * those can be a fixture, because what each is about is what the file is not.
 */
trait TemporaryFiles
{
    private ?string $temporaryDirectory = null;

    protected function temporaryPath(string $name): string
    {
        if ($this->temporaryDirectory === null) {
            $directory = sys_get_temp_dir().'/cv-tailor-test-'.bin2hex(random_bytes(8));

            (new Filesystem)->ensureDirectoryExists($directory);

            $this->temporaryDirectory = $directory;

            // Laravel's own teardown hook, so the clean-up is registered by
            // the thing that made the mess rather than by every test that
            // uses it remembering to.
            $this->beforeApplicationDestroyed(function () use ($directory): void {
                (new Filesystem)->deleteDirectory($directory);

                $this->temporaryDirectory = null;
            });
        }

        return $this->temporaryDirectory.'/'.$name;
    }
}
