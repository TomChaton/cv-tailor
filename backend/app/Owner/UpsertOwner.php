<?php

namespace App\Owner;

use App\Models\User;
use App\Observability\EventLogger;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;

/**
 * Creates the owner account, or updates it if it is already there.
 *
 * The distribution model is single-tenant: one instance, one person. So this
 * never adds a second account — it edits the one that exists.
 *
 * The rules live here rather than in the command, so that every way of
 * reaching an owner account — a prompt, a flag, the environment — is held to
 * the same ones.
 */
class UpsertOwner
{
    public function __construct(
        private readonly EventLogger $log,
        private readonly ValidationFactory $validator,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $name, string $email, string $password): User
    {
        $this->validator->make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                // Long enough that an instance exposed by mistake is not
                // opened by guessing.
                'password' => ['required', 'string', 'min:12'],
            ],
        )->validate();

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
