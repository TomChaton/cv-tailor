<?php

namespace App\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The one way this application writes a log line.
 *
 * It exists so that `event` is never optional, and so that no caller has to
 * remember that the event name also serves as the line's message.
 */
class EventLogger
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param  array<string, mixed>  $context  Identifiers, counts and outcomes
     *                                         only: never CV content, job
     *                                         description text or token values.
     */
    public function log(string $event, array $context = [], string $level = LogLevel::INFO): void
    {
        $this->logger->log($level, $event, ['event' => $event] + $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->log($event, $context, LogLevel::WARNING);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->log($event, $context, LogLevel::ERROR);
    }
}
