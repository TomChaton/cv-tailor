<?php

namespace App\Providers;

use Anthropic\Client;
use App\Llm\Anthropic\AnthropicLlmClient;
use App\Llm\CostCalculator;
use App\Llm\LlmClient;
use App\Llm\LlmEvents;
use App\Llm\ModelRegistry;
use App\Llm\Testing\FakeLlmClient;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires the LLM client.
 *
 * The interface is bound to the fake in the test environment, so reaching a
 * live model from a test takes deliberately undoing this. The provider's own
 * SDK client refuses to be built there at all, which catches a test that
 * resolves the real implementation directly rather than the interface.
 */
class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModelRegistry::class, function (): ModelRegistry {
            $models = [];

            foreach (Config::array('cv.llm.models') as $purpose => $model) {
                if (is_string($purpose) && is_string($model)) {
                    $models[$purpose] = $model;
                }
            }

            return new ModelRegistry($models);
        });

        $this->app->singleton(CostCalculator::class, fn (): CostCalculator => CostCalculator::fromConfig(
            Config::array('cv.llm.rates'),
            Config::integer('cv.llm.batch_percent'),
        ));

        $this->app->singleton(Client::class, function (Application $app): Client {
            if ($app->environment('testing')) {
                throw new RuntimeException(
                    'A test asked for a live LLM client. Depend on App\Llm\LlmClient, which is '
                    .'bound to the fake in the test environment.'
                );
            }

            $apiKey = Config::string('cv.llm.api_key');

            // The SDK hunts for credentials in the environment and on disk when
            // it is handed none. Refusing an empty key keeps the one place
            // credentials come from the one place they come from.
            if ($apiKey === '') {
                throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
            }

            return new Client(apiKey: $apiKey);
        });

        // The implementation holds a factory rather than the SDK client, so
        // that resolving it costs nothing and a run with no work needs no
        // credentials. Whether the key is set is reported by `cv:status`.
        //
        // Contextual binding on the type rather than on `$sdkFactory`: a
        // parameter the container can see a class name for is resolved as a
        // class, so a name-keyed binding would never be consulted.
        $this->app->when(AnthropicLlmClient::class)
            ->needs(Closure::class)
            ->give(fn (Application $app): Closure => fn (): Client => $app->make(Client::class));

        $this->app->singleton(FakeLlmClient::class, fn (Application $app): FakeLlmClient => new FakeLlmClient(
            Config::string('cv.llm.fixture_path'),
            $app->make(CostCalculator::class),
            $app->make(ModelRegistry::class),
            $app->make(LlmEvents::class),
        ));

        $this->app->singleton(
            LlmClient::class,
            fn (Application $app): LlmClient => $app->environment('testing')
                ? $app->make(FakeLlmClient::class)
                : $app->make(AnthropicLlmClient::class),
        );
    }
}
