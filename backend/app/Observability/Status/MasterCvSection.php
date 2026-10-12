<?php

namespace App\Observability\Status;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;

class MasterCvSection implements StatusSection
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $path,
    ) {}

    public function heading(): string
    {
        return 'Master CV';
    }

    public function rows(): array
    {
        $state = $this->state();

        $rows = [
            'path' => $this->path,
            'state' => $state->value,
        ];

        // A modification time is only a fact about a master CV when there is
        // one. Reporting it for anything else dresses a missing file up as a
        // present one.
        if ($state === MasterCvState::Readable) {
            $rows['modified'] = CarbonImmutable::createFromTimestamp(
                $this->files->lastModified($this->path)
            )->toIso8601String();
        }

        return $rows;
    }

    private function state(): MasterCvState
    {
        if (! $this->files->exists($this->path)) {
            return MasterCvState::NotFound;
        }

        if (! $this->files->isFile($this->path)) {
            return $this->files->isDirectory($this->path)
                ? MasterCvState::Directory
                : MasterCvState::NotRegularFile;
        }

        return $this->files->isReadable($this->path)
            ? MasterCvState::Readable
            : MasterCvState::NotReadable;
    }
}
