<?php

namespace Tests\Unit\Llm;

use App\Llm\LlmPurpose;
use App\Llm\ModelRegistry;
use App\Llm\UnknownModel;
use PHPUnit\Framework\TestCase;

class ModelRegistryTest extends TestCase
{
    public function test_each_purpose_has_its_own_model(): void
    {
        $registry = new ModelRegistry([
            'generation' => 'big-model',
            'validation' => 'small-model',
        ]);

        $this->assertSame('big-model', $registry->for(LlmPurpose::Generation));
        $this->assertSame('small-model', $registry->for(LlmPurpose::Validation));
    }

    /**
     * Every purpose is reported, including one nothing is configured for: a
     * purpose missing from the report cannot be told apart from one that has
     * been renamed.
     */
    public function test_it_reports_what_every_purpose_is_configured_with(): void
    {
        $registry = new ModelRegistry([
            'generation' => 'big-model',
            'validation' => 'small-model',
            'extraction' => '',
        ]);

        $this->assertSame([
            'generation' => 'big-model',
            'validation' => 'small-model',
            'extraction' => null,
        ], $registry->configured());
    }

    public function test_a_purpose_with_no_configured_model_raises(): void
    {
        $this->expectException(UnknownModel::class);

        (new ModelRegistry(['generation' => 'big-model']))->for(LlmPurpose::Validation);
    }
}
