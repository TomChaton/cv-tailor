<?php

namespace App\Observability\Status;

use App\Observability\QueueMetrics;

class QueueSection implements StatusSection
{
    public function __construct(private readonly QueueMetrics $metrics) {}

    public function heading(): string
    {
        return 'Queue';
    }

    public function rows(): array
    {
        return [
            'pending jobs' => (string) $this->metrics->pending(),
            'failed jobs' => (string) $this->metrics->failed(),
        ];
    }
}
