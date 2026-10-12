<?php

namespace App\Llm;

/**
 * Which model serves which purpose.
 *
 * Model names are configuration, so this is the only place code learns one.
 */
final readonly class ModelRegistry
{
    /**
     * @param  array<string, string>  $models  Keyed by purpose.
     */
    public function __construct(private array $models) {}

    public function for(LlmPurpose $purpose): string
    {
        $model = $this->models[$purpose->value] ?? '';

        if ($model === '') {
            throw UnknownModel::forPurpose($purpose);
        }

        return $model;
    }
}
