<?php

namespace App\Console\Commands;

use App\Observability\EventLogger;
use App\Owner\SeedCredentials;
use Illuminate\Console\Command;
use Throwable;

class CvSeedCredentialsCommand extends Command
{
    protected $signature = 'cv:seed-credentials';

    protected $description = 'Bring the owner account and the extension token up from the environment, where it names them.';

    public function handle(SeedCredentials $seed, EventLogger $log): int
    {
        try {
            if (! $seed->handle()) {
                $this->components->warn(
                    'Seeding is refused where APP_ENV is production. Run php artisan cv:owner and php artisan cv:token instead.'
                );
            }
        } catch (Throwable $e) {
            // The class of the failure and nothing else: an exception message
            // can quote the statement that produced it, and that statement
            // carries the password. Catching here rather than in the action
            // leaves the action free to throw, which is the behaviour a locked
            // or unwritable database deserves everywhere else.
            $log->error('credentials.seeding_failed', ['exception' => $e::class]);
        }

        // Always successful. The entrypoint runs this on every boot under
        // `set -e`, and a stack that will not start is a worse answer to a
        // credential it could not use than a stack that starts with the reason
        // in its log.
        return self::SUCCESS;
    }
}
