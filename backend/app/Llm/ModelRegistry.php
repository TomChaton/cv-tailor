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

    /**
     * What is configured for each purpose, with a null where nothing is.
     *
     * Every purpose is reported, including one nothing is configured for: a
     * purpose missing from a report cannot be told apart from one that has
     * been renamed.
     *
     * @return array<string, string|null> Keyed by purpose.
     */
    public function configured(): array
    {
        $models = [];

        foreach (LlmPurpose::cases() as $purpose) {
            $model = $this->models[$purpose->value] ?? '';

            $models[$purpose->value] = $model === '' ? null : $model;
        }

        return $models;
    }

    public function for(LlmPurpose $purpose): string
    {
        $model = $this->models[$purpose->value] ?? '';

        if ($model === '') {
            throw UnknownModel::forPurpose($purpose);
        }

        return $model;
    }
}
