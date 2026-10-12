<?php

namespace App\Owner;

use App\Models\User;

/**
 * Finds the single owner account. Single-tenant, so "the first row" is "the
 * owner" and nothing has to decide between candidates.
 */
class OwnerAccount
{
    public function find(): ?User
    {
        return User::query()->oldest('id')->first();
    }
}
