<?php

namespace App\Observability\Status;

use App\Owner\OwnerAccount;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Whether anybody can get in, and whether the extension can send.
 *
 * They are the two preconditions a fresh stack fails on, and both are
 * invisible from the outside: a review UI nobody has an account for and an API
 * nothing holds a token for look exactly like a stack that is working. So they
 * report here, beside the API key, and each names the command that supplies it.
 *
 * Neither the password nor any token is read, let alone printed. Whether they
 * are there is the only question this answers.
 */
class AccessSection implements StatusSection
{
    public function __construct(private readonly OwnerAccount $owners) {}

    public function heading(): string
    {
        return 'Access';
    }

    public function rows(): array
    {
        return [
            'owner' => $this->owners->find() === null
                ? 'none: run php artisan cv:owner'
                : 'yes',
            'extension token' => PersonalAccessToken::query()->exists()
                ? 'yes'
                : 'none: run php artisan cv:token',
        ];
    }
}
