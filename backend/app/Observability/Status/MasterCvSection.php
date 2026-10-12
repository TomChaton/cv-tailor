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
        $readable = $this->files->exists($this->path) && $this->files->isReadable($this->path);

        return [
            'path' => $this->path,
            'readable' => $readable ? 'yes' : 'no',
            'modified' => $readable
                ? CarbonImmutable::createFromTimestamp($this->files->lastModified($this->path))->toIso8601String()
                : 'unknown',
        ];
    }
}
