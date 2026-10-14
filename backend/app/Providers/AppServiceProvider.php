<?php

namespace App\Providers;

use App\Batching\ApplyResult;
use App\Batching\BatchRequestBuilder;
use App\Batching\PollBatches;
use App\Generation\BuildGenerationRequest;
use App\Http\Middleware\LimitRequestBody;
use App\Http\TrustedProxies;
use App\Observability\Listeners\RecordWorkerHeartbeat;
use App\Observability\Status\LlmSection;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Laravel registers the failed-job provider under the string key
        // `queue.failer` only, so the interface needs pointing at it before it
        // can be constructor-injected.
        $this->app->bind(
            CountableFailedJobProvider::class,
            function (Application $app): CountableFailedJobProvider {
                $failer = $app->make('queue.failer');

                if (! $failer instanceof CountableFailedJobProvider) {
                    throw new RuntimeException('The configured failed job provider cannot be counted.');
                }

                return $failer;
            },
        );

        // The batch lifecycle moves revisions between states and knows nothing
        // about prompts. Generation knows about prompts and nothing about
        // batches. This is the one place that says which builder the submitter
        // uses.
        $this->app->bind(BatchRequestBuilder::class, BuildGenerationRequest::class);

        // Contextual binding: a class wants one configured value, and the
        // alternative is a config lookup buried inside domain code.
        $this->app->when(BuildGenerationRequest::class)
            ->needs('$maxTokens')
            ->give(fn (): int => Config::integer('cv.llm.max_output_tokens.generation'));

        $this->app->when(LlmSection::class)
            ->needs('$apiKey')
            ->give(fn (): string => Config::string('cv.llm.api_key'));

        $this->app->when(PollBatches::class)
            ->needs('$staleAfterMinutes')
            ->give(fn (): int => Config::integer('cv.batches.stale_after_minutes'));

        $this->app->when(ApplyResult::class)
            ->needs('$attemptLimit')
            ->give(fn (): int => Config::integer('cv.batches.attempt_limit'));

        $this->app->when(LimitRequestBody::class)
            ->needs('$maxBytes')
            ->give(fn (): int => Config::integer('cv.api.max_body_bytes'));
    }

    public function boot(): void
    {
        Event::listen(Looping::class, RecordWorkerHeartbeat::class);

        // Configured here rather than in bootstrap/app.php: the middleware
        // callback there runs when the HTTP kernel is resolved, before the
        // config repository is loaded. TrustProxies reads this static when it
        // handles a request, so setting it on boot is in time.
        $proxies = TrustedProxies::from(Config::string('cv.trusted_proxies'));

        if ($proxies !== null) {
            TrustProxies::at($proxies);
        }
    }
}
