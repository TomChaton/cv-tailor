<?php

namespace Tests\Unit\Rendering;

use App\Rendering\Html\InlineMarkdown;
use PHPUnit\Framework\TestCase;

class InlineMarkdownTest extends TestCase
{
    public function test_a_bold_lead_in_becomes_strong(): void
    {
        $this->assertSame(
            '<strong>Dispatch API (designed and built).</strong> Replaced a batch hand-off.',
            $this->html('**Dispatch API (designed and built).** Replaced a batch hand-off.'),
        );
    }

    public function test_emphasis_and_code_are_converted(): void
    {
        $this->assertSame('An <em>index alias</em> and a <code>TODO</code>.', $this->html('An *index alias* and a `TODO`.'));
    }

    public function test_a_link_keeps_its_text_and_drops_the_url(): void
    {
        $this->assertSame('See the handbook.', $this->html('See the [handbook](https://example.com/handbook).'));
    }

    /**
     * Everything is escaped before any of it is read as Markdown, so no part
     * of the master CV or of the model's output can contribute a tag.
     */
    public function test_html_in_the_text_cannot_become_html_on_the_page(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; &amp; <strong>then</strong>',
            $this->html('<script>alert(1)</script> & **then**'),
        );
    }

    public function test_a_lone_asterisk_is_left_alone(): void
    {
        $this->assertSame('2 * 3 and 5 * 7', $this->html('2 * 3 and 5 * 7'));
    }

    public function test_a_blank_text_renders_nothing(): void
    {
        $this->assertSame('', $this->html(null));
        $this->assertSame('', $this->html('   '));
    }

    public function test_a_block_keeps_its_line_breaks(): void
    {
        $this->assertSame(
            'One line.<br>And <strong>another</strong>.',
            (new InlineMarkdown)->block("One line.\n\nAnd **another**.")->toHtml(),
        );
    }

    private function html(?string $text): string
    {
        return (new InlineMarkdown)->line($text)->toHtml();
    }
}
