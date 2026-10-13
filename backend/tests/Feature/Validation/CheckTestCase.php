<?php

namespace Tests\Feature\Validation;

use App\Validation\Check;
use App\Validation\Finding;
use App\Validation\ValidationContext;
use Tests\Concerns\MasterCvFixtures;
use Tests\Concerns\SelectionFixtures;
use Tests\TestCase;

/**
 * One check, run against the fictional master CV.
 *
 * A check writes its messages from the language catalogue, so these boot the
 * application rather than constructing the check by hand.
 */
abstract class CheckTestCase extends TestCase
{
    use MasterCvFixtures;
    use SelectionFixtures;

    abstract protected function check(): Check;

    /**
     * @param  array<string, mixed>  $output
     * @return list<Finding>
     */
    protected function findings(array $output): array
    {
        return $this->check()->run(ValidationContext::for($this->parseFixture(), $output));
    }

    /**
     * @param  array<string, mixed>  $output
     */
    protected function assertPasses(array $output): void
    {
        $findings = $this->findings($output);

        $this->assertSame([], array_map(
            static fn (Finding $finding): string => $finding->message,
            $findings,
        ));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<Finding>
     */
    protected function assertFails(array $output, int $count = 1): array
    {
        $findings = $this->findings($output);

        $this->assertCount($count, $findings, 'Expected '.$count.' finding(s), got: '.implode(' / ', array_map(
            static fn (Finding $finding): string => $finding->message,
            $findings,
        )));

        foreach ($findings as $finding) {
            $this->assertSame($this->check()->name(), $finding->check);
            $this->assertNotSame('', $finding->message);
        }

        return $findings;
    }
}
