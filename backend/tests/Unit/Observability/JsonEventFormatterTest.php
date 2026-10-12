<?php

namespace Tests\Unit\Observability;

use App\Observability\JsonEventFormatter;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class JsonEventFormatterTest extends TestCase
{
    public function test_it_names_the_event_and_lifts_context_to_the_top_level(): void
    {
        $line = $this->format(['event' => 'posting.captured', 'posting_id' => 7]);

        $this->assertSame('posting.captured', $line['event']);
        $this->assertSame(7, $line['posting_id']);
        $this->assertSame('info', $line['level']);
    }

    public function test_a_line_with_no_event_is_named_as_the_bug_it_is(): void
    {
        $line = $this->format([]);

        $this->assertSame(JsonEventFormatter::UNNAMED_EVENT, $line['event']);
        $this->assertSame('something happened', $line['message']);
    }

    public function test_context_cannot_overwrite_the_keys_the_line_owns(): void
    {
        $line = $this->format([
            'event' => 'batch.submitted',
            'level' => 'emergency',
            'time' => 'whenever',
            'channel' => 'elsewhere',
        ]);

        $this->assertSame('info', $line['level']);
        $this->assertNotSame('whenever', $line['time']);
        $this->assertSame('testing', $line['channel']);
    }

    public function test_every_line_is_one_json_object_on_one_line(): void
    {
        $formatted = (new JsonEventFormatter)->format($this->record(['event' => 'worker.heartbeat']));

        $this->assertStringEndsWith("\n", $formatted);
        $this->assertSame(1, substr_count($formatted, "\n"));
        $this->assertStringContainsString('"event":"worker.heartbeat"', $formatted);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function format(array $context): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (new JsonEventFormatter)->format($this->record($context)),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(array $context): LogRecord
    {
        return new LogRecord(
            datetime: new JsonSerializableDateTimeImmutable(true),
            channel: 'testing',
            level: Level::Info,
            message: 'something happened',
            context: $context,
        );
    }
}
