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

    public function test_a_purpose_with_no_configured_model_raises(): void
    {
        $this->expectException(UnknownModel::class);

        (new ModelRegistry(['generation' => 'big-model']))->for(LlmPurpose::Validation);
    }
}
