<?php

namespace App\Observability\Status;

use App\MasterCv\MasterCvState;
use App\MasterCv\ReadMasterCv;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class MasterCvSection implements StatusSection
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ReadMasterCv $read,
        private readonly string $path,
    ) {}

    public function heading(): string
    {
        return 'Master CV';
    }

    public function rows(): array
    {
        $state = MasterCvState::at($this->files, $this->path);

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

            $rows = [...$rows, ...$this->parse()];
        }

        return $rows;
    }

    /**
     * The version the file parses to now, and whether it would be refused.
     *
     * Both are read live rather than recalled: the file on disk is the editing
     * surface, so the question this answers is "what would generating from it
     * use, and would it be allowed to".
     *
     * @return array<string, string>
     */
    private function parse(): array
    {
        try {
            [, $parsed, $report] = $this->read->handle($this->path);
        } catch (Throwable) {
            return ['lint' => 'could not be checked'];
        }

        $problems = count($report->problems());

        return [
            'version' => $parsed->hash(),
            'lint' => $problems === 0
                ? 'passing'
                : $problems.' problem'.($problems === 1 ? '' : 's').': run php artisan cv:lint',
        ];
    }
}
