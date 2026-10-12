<?php

namespace Tests\Unit\Generation;

use App\Generation\MasterCvPrompt;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MasterCvFixtures;

class MasterCvPromptTest extends TestCase
{
    use MasterCvFixtures;

    public function test_it_sends_the_cvs_rules_word_for_word(): void
    {
        $cv = $this->parseFixture();
        $text = (new MasterCvPrompt)->text($cv);

        $this->assertStringContainsString(trim($cv->agentRules->raw), $text);
        $this->assertStringContainsString(trim($cv->doNotClaim->raw), $text);
    }

    public function test_it_sends_the_facts_the_model_selects_from(): void
    {
        $text = (new MasterCvPrompt)->text($this->parseFixture());

        $this->assertStringContainsString('harlequin-transit.4.3', $text);
        $this->assertStringContainsString('"default_mode": "omitted"', $text);
        $this->assertStringContainsString('Senior Backend Engineer', $text);
    }

    /**
     * The rules are above the facts word for word, so the copy of them inside
     * the facts would be a second reading of the same lines.
     */
    public function test_the_rules_are_not_repeated_among_the_facts(): void
    {
        $text = (new MasterCvPrompt)->text($this->parseFixture());

        $this->assertStringNotContainsString('"agent_rules"', $text);
        $this->assertStringNotContainsString('"do_not_claim"', $text);
    }

    /**
     * The presentation policy belongs to the run rather than to the CV, so the
     * brief states it, and states it once.
     */
    public function test_the_presentation_policy_is_left_to_the_brief(): void
    {
        $this->assertStringNotContainsString(
            '"presentation"',
            (new MasterCvPrompt)->text($this->parseFixture()),
        );
    }

    /**
     * The rendered CV's header is built from the parsed contact section by the
     * template, so the model cannot mistype a phone number it never saw.
     */
    public function test_identity_never_reaches_the_model(): void
    {
        $cv = $this->parseFixture();
        $text = (new MasterCvPrompt)->text($cv);

        $this->assertNotSame([], $cv->contact->values());

        foreach ($cv->contact->values() as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
    }
}
