<?php

namespace App\Console\Commands;

use App\Owner\EnvironmentCredentials;
use App\Owner\IssueExtensionToken;
use App\Owner\OwnerAccount;
use App\Owner\StoreExtensionToken;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CvTokenCommand extends Command
{
    protected $signature = 'cv:token {--name=extension}';

    protected $description = 'Issue a bearer token for the extension and print it once.';

    public function handle(
        OwnerAccount $owners,
        IssueExtensionToken $issue,
        StoreExtensionToken $store,
        EnvironmentCredentials $credentials,
    ): int {
        $owner = $owners->find();

        if ($owner === null) {
            $this->components->error('No owner account. Run `php artisan cv:owner` first.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $name = is_string($name) && $name !== '' ? $name : StoreExtensionToken::DEFAULT_NAME;

        if ($credentials->hasExtensionToken()) {
            try {
                $store->handle($owner, $name, $credentials->extensionToken);
            } catch (ValidationException $e) {
                foreach ($e->validator->errors()->all() as $message) {
                    $this->components->error($message);
                }

                return self::FAILURE;
            }

            // A token it was given is one the owner already has, so there is
            // no reason to repeat it.
            $this->components->info('Extension token stored from CV_EXTENSION_TOKEN.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($issue->handle($owner, $name));
        $this->newLine();
        $this->components->warn('Shown once. Paste it into the extension now.');

        return self::SUCCESS;
    }
}
