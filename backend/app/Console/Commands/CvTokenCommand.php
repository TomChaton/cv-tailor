<?php

namespace App\Console\Commands;

use App\Owner\IssueExtensionToken;
use App\Owner\OwnerAccount;
use Illuminate\Console\Command;

class CvTokenCommand extends Command
{
    protected $signature = 'cv:token {--name=extension}';

    protected $description = 'Issue a bearer token for the extension and print it once.';

    public function handle(OwnerAccount $owners, IssueExtensionToken $issue): int
    {
        $owner = $owners->find();

        if ($owner === null) {
            $this->components->error('No owner account. Run `php artisan cv:owner` first.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $name = is_string($name) && $name !== '' ? $name : 'extension';

        $this->newLine();
        $this->line($issue->handle($owner, $name));
        $this->newLine();
        $this->components->warn('Shown once. Paste it into the extension now.');

        return self::SUCCESS;
    }
}
