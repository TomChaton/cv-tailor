<?php

namespace App\Providers;

use App\Generation\BuildGenerationRequest;
use App\Http\TrustedProxies;
use App\MasterCv\ReadMasterCv;
use App\Observability\Listeners\RecordWorkerHeartbeat;
use App\Observability\Status\MasterCvSection;
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

        // Contextual binding: these classes want the master CV path, and the
        // alternative is a config lookup buried inside domain code.
        $this->app->when([MasterCvSection::class, ReadMasterCv::class])
            ->needs('$path')
            ->give(fn (): string => Config::string('cv.master_cv.path'));

        $this->app->when(BuildGenerationRequest::class)
            ->needs('$maxTokens')
            ->give(fn (): int => Config::integer('cv.llm.max_output_tokens.generation'));
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
