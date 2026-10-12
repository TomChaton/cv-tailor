<?php

namespace App\Observability;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Renders every log line as one JSON object naming an `event`.
 *
 * Log lines are an interface: they are grepped by event name and read by
 * people diagnosing a stopped pipeline. Context keys are flattened to the top
 * level so a single grep can match both the event and the object it concerns.
 */
class JsonEventFormatter extends NormalizerFormatter
{
    /**
     * Given to a line whose logging call supplied no event name. Such a call is
     * a bug; naming it makes it findable rather than letting it pass as normal.
     */
    public const string UNNAMED_EVENT = 'log.unnamed';

    /**
     * Keys the line itself owns. Context may not overwrite them.
     *
     * @var list<string>
     */
    private const array RESERVED = ['time', 'level', 'event', 'message', 'channel'];

    public function __construct()
    {
        parent::__construct('Y-m-d\TH:i:s.vP');
    }

    public function format(LogRecord $record): string
    {
        /** @var array<string, mixed> $context */
        $context = (array) $this->normalize($record->context);

        $event = $context['event'] ?? null;

        $line = [
            'time' => $record->datetime->format($this->dateFormat),
            'level' => strtolower($record->level->getName()),
            'event' => is_string($event) && $event !== '' ? $event : self::UNNAMED_EVENT,
            'message' => $record->message,
            'channel' => $record->channel,
        ];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::RESERVED, true)) {
                $line[$key] = $value;
            }
        }

        return $this->toJson($line, true)."\n";
    }
}
