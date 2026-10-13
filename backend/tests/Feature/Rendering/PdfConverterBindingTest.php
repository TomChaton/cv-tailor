<?php

namespace Tests\Feature\Rendering;

use App\Rendering\Gotenberg\GotenbergPdfConverter;
use App\Rendering\PdfConverter;
use App\Rendering\Testing\FakePdfConverter;
use RuntimeException;
use Tests\TestCase;

class PdfConverterBindingTest extends TestCase
{
    public function test_the_interface_resolves_to_the_fake_in_the_test_environment(): void
    {
        $this->assertInstanceOf(FakePdfConverter::class, $this->app->make(PdfConverter::class));
    }

    public function test_the_same_fake_is_shared_so_a_test_can_steer_what_the_code_under_test_gets(): void
    {
        $this->assertSame($this->app->make(PdfConverter::class), $this->app->make(FakePdfConverter::class));
    }

    /**
     * A test that resolves the implementation instead of the interface would
     * otherwise start a browser in another container.
     */
    public function test_building_a_live_converter_in_a_test_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app->make(GotenbergPdfConverter::class);
    }
}
