<?php

namespace App\Batching;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Stops the submitter when the requests it builds are being refused.
 *
 * Two consecutive batches whose every result is a malformed request halt it.
 * One such batch is more likely a single pathological job description than a
 * broken schema, and a batch of one is the normal case here, so a
 * single-batch rule would let one oversized posting stop all generation.
 *
 * The poller is never halted: batches already in flight still need
 * collecting, and stopping both would turn a schema bug into work paid for and
 * thrown away.
 *
 * The state lives in the cache, keyed as the heartbeats are, so it survives a
 * container restart without owning schema. Only `cv:batch-resume` clears it —
 * not a deploy, not a restart, not a timer. A state that cleared itself would
 * let a restart quietly resume submitting broken requests.
 */
class SubmitterHalt
{
    /**
     * Batches it takes to halt. One is a job description; two in a row is the
     * request builder.
     */
    private const int CONSECUTIVE = 2;

    private const string HALTED = 'cv-tailor:batch:submitter-halted';

    private const string STREAK = 'cv-tailor:batch:invalid-streak';

    public function __construct(private readonly Repository $cache) {}

    public function isHalted(): bool
    {
        return $this->halted() !== null;
    }

    /**
     * The batches whose results tripped the halt, in the order they were
     * collected.
     *
     * @return list<int>
     */
    public function haltedBy(): array
    {
        return $this->halted()['llm_batch_ids'] ?? [];
    }

    public function haltedAt(): ?CarbonImmutable
    {
        $at = $this->halted()['at'] ?? null;

        return $at === null ? null : CarbonImmutable::parse($at);
    }

    /**
     * Record a batch every one of whose results was a malformed request.
     *
     * @return list<int>|null The batches that tripped the halt, when this is
     *                        the one that trips it. Null otherwise, including
     *                        when the halt is already in place.
     */
    public function recordFullyInvalid(int $llmBatchId): ?array
    {
        $streak = $this->streak();

        // Keyed by batch rather than counted, because a batch is collected
        // over as many poll runs as it takes and must not be able to trip the
        // halt twice on its own.
        if (! in_array($llmBatchId, $streak, strict: true)) {
            $streak[] = $llmBatchId;
            $this->cache->forever(self::STREAK, $streak);
        }

        if ($this->isHalted() || count($streak) < self::CONSECUTIVE) {
            return null;
        }

        $this->cache->forever(self::HALTED, [
            'llm_batch_ids' => $streak,
            'at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        return $streak;
    }

    /**
     * Record a batch that produced something the provider was willing to
     * serve. It breaks the streak, which is what makes the rule "two
     * consecutive" rather than "two ever".
     */
    public function recordUsableResults(): void
    {
        $this->cache->forget(self::STREAK);
    }

    /**
     * @return list<int> The batches the halt was holding.
     */
    public function resume(): array
    {
        $ids = $this->haltedBy();

        $this->cache->forget(self::HALTED);
        $this->cache->forget(self::STREAK);

        return $ids;
    }

    /**
     * @return array{llm_batch_ids: list<int>, at: string}|null
     */
    private function halted(): ?array
    {
        $value = $this->cache->get(self::HALTED);

        if (! is_array($value) || ! is_array($value['llm_batch_ids'] ?? null) || ! is_string($value['at'] ?? null)) {
            return null;
        }

        /** @var array{llm_batch_ids: list<int>, at: string} $value */
        return $value;
    }

    /**
     * @return list<int>
     */
    private function streak(): array
    {
        $value = $this->cache->get(self::STREAK);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_int'));
    }
}
