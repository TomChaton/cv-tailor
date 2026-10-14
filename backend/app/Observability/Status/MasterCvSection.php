<?php

namespace App\Observability\Status;

use App\MasterCv\ReadMasterCv;
use Throwable;

class MasterCvSection implements StatusSection
{
    public function __construct(private readonly ReadMasterCv $read) {}

    public function heading(): string
    {
        return 'Master CV';
    }

    public function rows(): array
    {
        $stored = $this->read->stored();

        // A fresh installation has no CV. That is the ordinary first state
        // rather than a fault, so it reads as one — and it names the two ways
        // out, because nothing else in the system will work until one is
        // taken.
        if ($stored === null) {
            return [
                'stored' => 'no: write one in the review UI, or run php artisan cv:import <path>',
            ];
        }

        return [
            'stored' => 'yes',
            'saved' => $stored->updated_at->toIso8601String(),
            ...$this->parse($stored->markdown),
        ];
    }

    /**
     * The version the stored CV parses to now, and whether it would be
     * refused.
     *
     * Both are read live rather than recalled: what this answers is "what
     * would generating from it use, and would it be allowed to".
     *
     * @return array<string, string>
     */
    private function parse(string $markdown): array
    {
        try {
            [$parsed, $report] = $this->read->parse($markdown);
        } catch (Throwable) {
            // `cv:status` is the first place somebody looks. A parser that
            // falls over must not take the rest of the report with it.
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
