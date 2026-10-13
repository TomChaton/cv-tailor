<?php

namespace Tests\Feature\Batching;

use App\Batching\SubmitterHalt;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SubmitterHaltTest extends TestCase
{
    public function test_it_is_not_halted_to_begin_with(): void
    {
        $this->assertFalse($this->halt()->isHalted());
        $this->assertSame([], $this->halt()->haltedBy());
        $this->assertNull($this->halt()->haltedAt());
    }

    public function test_one_fully_invalid_batch_does_not_halt_it(): void
    {
        $this->assertNull($this->halt()->recordFullyInvalid(1));
        $this->assertFalse($this->halt()->isHalted());
    }

    public function test_two_halt_it_and_name_the_batches_that_did(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));

        $this->halt()->recordFullyInvalid(7);

        $this->assertSame([7, 9], $this->halt()->recordFullyInvalid(9));
        $this->assertTrue($this->halt()->isHalted());
        $this->assertSame([7, 9], $this->halt()->haltedBy());
        $this->assertSame('2026-01-01T12:00:00+00:00', $this->halt()->haltedAt()?->toIso8601String());
    }

    public function test_the_same_batch_twice_is_one_batch(): void
    {
        $this->halt()->recordFullyInvalid(7);

        $this->assertNull($this->halt()->recordFullyInvalid(7));
        $this->assertFalse($this->halt()->isHalted());
    }

    public function test_a_batch_with_usable_results_breaks_the_streak(): void
    {
        $this->halt()->recordFullyInvalid(7);
        $this->halt()->recordUsableResults();

        $this->assertNull($this->halt()->recordFullyInvalid(9));
        $this->assertFalse($this->halt()->isHalted());
    }

    /**
     * Breaking the streak is not the same as resuming: a halt that a later
     * good batch could clear would let the submitter arm itself.
     */
    public function test_usable_results_do_not_clear_a_halt(): void
    {
        $this->halt()->recordFullyInvalid(7);
        $this->halt()->recordFullyInvalid(9);

        $this->halt()->recordUsableResults();

        $this->assertTrue($this->halt()->isHalted());
    }

    public function test_a_halted_submitter_does_not_halt_twice(): void
    {
        $this->halt()->recordFullyInvalid(7);
        $this->halt()->recordFullyInvalid(9);

        $this->assertNull($this->halt()->recordFullyInvalid(11));
        $this->assertSame([7, 9], $this->halt()->haltedBy());
    }

    public function test_resuming_clears_the_halt_and_the_streak(): void
    {
        $this->halt()->recordFullyInvalid(7);
        $this->halt()->recordFullyInvalid(9);

        $this->assertSame([7, 9], $this->halt()->resume());
        $this->assertFalse($this->halt()->isHalted());

        // The streak went with it: the next bad batch is the first of two
        // again, not the second.
        $this->assertNull($this->halt()->recordFullyInvalid(11));
        $this->assertFalse($this->halt()->isHalted());
    }

    private function halt(): SubmitterHalt
    {
        return $this->app->make(SubmitterHalt::class);
    }
}
