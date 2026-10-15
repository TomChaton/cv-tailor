<?php

namespace App\Observability\Status;

use App\Llm\CostCalculator;
use App\Llm\ModelRegistry;

/**
 * Whether this instance can reach a model at all, and price what it gets back.
 *
 * Two preconditions live here, and both are answered without calling anybody.
 *
 * A key that is not set is a precondition the owner can see and fix, and this
 * is where it belongs: the alternative is every scheduled run reporting a
 * failure, which trains a reader to ignore the channel that has to stay worth
 * reading.
 *
 * A configured model with no configured rate is the same kind of fact. Nothing
 * can be priced by it, so every request for that purpose spends its attempts
 * and gives up — extraction leaving a posting with no title, generation
 * leaving a revision that never arrives. Whether every purpose has a rate is
 * knowable before anything runs, so it is reported here rather than learned
 * from a failure.
 *
 * The key itself is never printed, in part or in whole. Whether it is there is
 * the only question this answers.
 */
class LlmSection implements StatusSection
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ModelRegistry $models,
        private readonly CostCalculator $cost,
    ) {}

    public function heading(): string
    {
        return 'LLM';
    }

    public function rows(): array
    {
        $rows = [
            'api key' => trim($this->apiKey) === ''
                ? 'not set: put ANTHROPIC_API_KEY in .env'
                : 'set',
        ];

        foreach ($this->models->configured() as $purpose => $model) {
            $rows[$purpose] = $this->model($purpose, $model);
        }

        return $rows;
    }

    /**
     * @param  string|null  $model  The model configured for the purpose, or
     *                              null where none is.
     */
    private function model(string $purpose, ?string $model): string
    {
        if ($model === null) {
            return sprintf('not set: put LLM_%s_MODEL in .env', strtoupper($purpose));
        }

        // The rate is looked up by the model the application asks for, which
        // is this name. A rate keyed on the name the provider reports would be
        // keyed on a model nobody chose, so this answer is the same one the
        // request will get.
        return $this->cost->hasRates($model)
            ? sprintf('%s (rate set)', $model)
            : sprintf('%s (no rate: add it to cv.llm.rates)', $model);
    }
}
