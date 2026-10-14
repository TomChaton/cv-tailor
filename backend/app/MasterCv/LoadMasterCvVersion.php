<?php

namespace App\MasterCv;

use App\Models\MasterCvVersion;

/**
 * The stored master CV as a version, ready for a revision to be generated
 * from.
 *
 * This is the gate, and it is the only thing that writes a version: a CV that
 * fails lint never becomes one, so no revision can reference one, and a
 * version exists because a generation used the text in it. Every generation
 * run goes through here.
 */
class LoadMasterCvVersion
{
    public function __construct(
        private readonly ReadMasterCv $read,
        private readonly StoreMasterCvVersion $store,
    ) {}

    public function handle(): MasterCvVersion
    {
        [$markdown, $parsed, $report] = $this->read->handle();

        if (! $report->passes()) {
            $this->read->logFailure($report);

            throw MasterCvIsNotUsable::failedLint($report);
        }

        return $this->store->handle($markdown, $parsed);
    }
}
