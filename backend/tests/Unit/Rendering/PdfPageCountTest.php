<?php

namespace Tests\Unit\Rendering;

use App\Rendering\PdfPageCount;
use PHPUnit\Framework\TestCase;

class PdfPageCountTest extends TestCase
{
    public function test_it_counts_the_page_objects(): void
    {
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Pages /Count 3 >>\nendobj\n"
            ."2 0 obj\n<< /Type /Page >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page >>\nendobj\n"
            ."4 0 obj\n<< /Type /Page >>\nendobj\n";

        $this->assertSame(3, PdfPageCount::of($pdf));
    }

    /**
     * The page tree is not a page, or every PDF would be one page longer than
     * it is.
     */
    public function test_the_page_tree_is_not_counted_as_a_page(): void
    {
        $this->assertSame(1, PdfPageCount::of("/Type /Pages\n/Type /Page\n"));
    }

    public function test_it_falls_back_to_the_declared_count(): void
    {
        $this->assertSame(4, PdfPageCount::of("%PDF-1.7\n<< /Type /Pages /Count 4 >>\n"));
    }

    /**
     * Null rather than zero: not knowing is a different fact from an empty
     * document.
     */
    public function test_it_answers_null_when_it_cannot_tell(): void
    {
        $this->assertNull(PdfPageCount::of('not a pdf'));
    }
}
