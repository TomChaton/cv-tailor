<?php

namespace App\MasterCv;

use App\Models\MasterCvVersion;
use App\Observability\EventLogger;

/**
 * Records the parsed CV as the version a revision can point at.
 *
 * Deduplicated by hash, so generating twice from an unchanged CV reuses the
 * row and both revisions agree on what they were built from.
 */
class StoreMasterCvVersion
{
    public function __construct(private readonly EventLogger $log) {}

    public function handle(string $raw, ParsedMasterCv $parsed): MasterCvVersion
    {
        $hash = $parsed->hash();

        $version = MasterCvVersion::firstOrCreate(
            ['hash' => $hash],
            ['raw_content' => $raw, 'parsed_json' => $parsed->toArray()],
        );

        // Counts, never content: a parser regression that silently drops half
        // the file shows up here and nowhere else.
        $this->log->log('master_cv.parsed', [
            'master_cv_version_id' => $version->id,
            'hash' => $hash,
            'stored' => $version->wasRecentlyCreated,
            ...$parsed->counts(),
        ]);

        return $version;
    }
}
