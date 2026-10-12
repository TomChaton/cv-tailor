<?php

namespace App\MasterCv;

use App\Models\MasterCvVersion;

/**
 * The master CV as a stored version, ready for a revision to be generated
 * from.
 *
 * This is the gate: a CV that fails lint never becomes a version, so no
 * revision can reference one. Every generation run goes through here.
 */
class LoadMasterCvVersion
{
    public function __construct(
        private readonly ReadMasterCv $read,
        private readonly StoreMasterCvVersion $store,
    ) {}

    public function handle(?string $path = null): MasterCvVersion
    {
        [$raw, $parsed, $report] = $this->read->handle($path);

        if (! $report->passes()) {
            $this->read->logFailure($this->read->path($path), $report);

            throw MasterCvIsNotUsable::failedLint($this->read->path($path), $report);
        }

        return $this->store->handle($raw, $parsed);
    }
}
