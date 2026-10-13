<?php

namespace Tests\Support;

use App\Observability\EventLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * Remembers the lines the code under test wrote.
 *
 * Event names are part of this application's interface, so tests assert on
 * them. `warning()` and `error()` both go through `log()`, so overriding the
 * one method catches all three levels.
 */
class RecordingEventLogger extends EventLogger
{
    /** @var list<array{event: string, level: string, context: array<string, mixed>}> */
    public array $lines = [];

    public function __construct()
    {
        parent::__construct(new NullLogger);
    }

    public function log(string $event, array $context = [], string $level = LogLevel::INFO): void
    {
        $this->lines[] = ['event' => $event, 'level' => $level, 'context' => $context];
    }

    public function has(string $event): bool
    {
        return $this->count($event) > 0;
    }

    public function count(string $event): int
    {
        return count(array_filter($this->lines, fn (array $line): bool => $line['event'] === $event));
    }

    /**
     * The first line written under this event name.
     *
     * @return array{event: string, level: string, context: array<string, mixed>}|null
     */
    public function first(string $event): ?array
    {
        foreach ($this->lines as $line) {
            if ($line['event'] === $event) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Every line written under this event name.
     *
     * @return list<array{event: string, level: string, context: array<string, mixed>}>
     */
    public function all(string $event): array
    {
        return array_values(array_filter($this->lines, fn (array $line): bool => $line['event'] === $event));
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        return array_map(fn (array $line): string => $line['event'], $this->lines);
    }
}
