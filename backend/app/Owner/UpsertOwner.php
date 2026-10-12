<?php

namespace App\Owner;

use App\Models\User;
use App\Observability\EventLogger;

/**
 * Creates the owner account, or updates it if it is already there.
 *
 * The distribution model is single-tenant: one instance, one person. So this
 * never adds a second account — it edits the one that exists.
 */
class UpsertOwner
{
    public function __construct(private readonly EventLogger $log) {}

    public function handle(string $name, string $email, string $password): User
    {
        $user = User::query()->oldest('id')->first() ?? new User;

        $user->name = $name;
        $user->email = $email;
        // The `password` cast hashes on assignment, so no plaintext is stored.
        $user->password = $password;

        $existed = $user->exists;
        $user->save();

        $this->log->log($existed ? 'owner.updated' : 'owner.created', [
            'user_id' => $user->getKey(),
        ]);

        return $user;
    }
}
