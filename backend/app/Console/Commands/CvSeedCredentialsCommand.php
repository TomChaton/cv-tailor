<?php

namespace App\Console\Commands;

use App\Owner\SeedCredentials;
use Illuminate\Console\Command;

class CvSeedCredentialsCommand extends Command
{
    protected $signature = 'cv:seed-credentials';

    protected $description = 'Bring the owner account and the extension token up from the environment, where it names them.';

    public function handle(SeedCredentials $seed): int
    {
        if (! $seed->handle()) {
            $this->components->warn(
                'Seeding is refused where APP_ENV is production. Run php artisan cv:owner and php artisan cv:token instead.'
            );
        }

        // Always successful. The entrypoint runs this on every boot under
        // `set -e`, and a stack that will not start is a worse answer to a
        // credential it could not use than a stack that starts with the reason
        // in its log.
        return self::SUCCESS;
    }
}
