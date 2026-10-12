<?php

namespace Tests\Unit\Generation;

use App\Generation\WordBudget;
use PHPUnit\Framework\TestCase;

class WordBudgetTest extends TestCase
{
    public function test_it_turns_pages_into_words(): void
    {
        $this->assertSame(1000, WordBudget::forPages(2));
        $this->assertSame(1500, WordBudget::forPages(3));
    }

    /**
     * A target of no pages is a target of nothing, which is not a CV.
     */
    public function test_it_never_budgets_for_less_than_a_page(): void
    {
        $this->assertSame(WordBudget::WORDS_PER_PAGE, WordBudget::forPages(0));
        $this->assertSame(WordBudget::WORDS_PER_PAGE, WordBudget::forPages(-4));
    }
}
