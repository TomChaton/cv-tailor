<?php

namespace App\Console\Commands;

use App\Owner\EnvironmentCredentials;
use App\Owner\UpsertOwner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CvOwnerCommand extends Command
{
    protected $signature = 'cv:owner {--name=} {--email=} {--password=}';

    protected $description = 'Create or update the owner account for the review UI.';

    public function handle(UpsertOwner $upsert, EnvironmentCredentials $credentials): int
    {
        // Flag, then environment, then ask. A flag is the most deliberate
        // thing the caller can have said, and a prompt is what is left when
        // nothing has been said at all.
        $name = $this->stringOption('name') ?? $this->given($credentials->ownerName) ?? text('Name', required: true);
        $email = $this->stringOption('email') ?? $this->given($credentials->ownerEmail) ?? text('Email', required: true);
        $plainPassword = $this->stringOption('password')
            ?? $this->given($credentials->ownerPassword)
            ?? password('Password', required: true);

        try {
            $user = $upsert->handle($name, $email, $plainPassword);
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

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

    private function given(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
