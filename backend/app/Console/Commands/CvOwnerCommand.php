<?php

namespace App\Console\Commands;

use App\Owner\UpsertOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CvOwnerCommand extends Command
{
    protected $signature = 'cv:owner {--name=} {--email=} {--password=}';

    protected $description = 'Create or update the owner account for the review UI.';

    public function handle(UpsertOwner $upsert): int
    {
        $name = $this->stringOption('name') ?? text('Name', required: true);
        $email = $this->stringOption('email') ?? text('Email', required: true);
        $plainPassword = $this->stringOption('password') ?? password('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = $upsert->handle($name, $email, $plainPassword);

        $this->components->info(
            $user->wasRecentlyCreated
                ? "Owner account created for {$user->email}."
                : "Owner account updated for {$user->email}."
        );

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
